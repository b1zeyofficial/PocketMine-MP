<?php

namespace pocketmine\world;

use pocketmine\block\Block;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\block\TNT;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\Entity;
use pocketmine\entity\Explosive;
use pocketmine\event\block\BlockExplodeEvent;
use pocketmine\event\entity\EntityDamageByBlockEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityExplodeEvent;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\TieredTool;
use pocketmine\item\ToolTier;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\world\particle\HugeExplodeSeedParticle;
use pocketmine\world\sound\ExplodeSound;
use pocketmine\world\utils\SubChunkExplorer;
use pocketmine\world\utils\SubChunkExplorerStatus;

class Explosion {

	private int $rays = 16;
	private array $affectedBlocks = [];
	private float $stepLen = 0.3;
	private bool $doesDamage = true;
	public float $fireChance;
	public \Ds\Set $fireIgnitions;
	private World $world;
	private SubChunkExplorer $subChunkExplorer;
	private float $minHeight = PHP_INT_MIN;

	public function __construct(public Position $source, public float $radius, private Entity|Block|null $what = null) {
		if (!$this->source->isValid()) {
			throw new \InvalidArgumentException("Position does not have a valid world");
		}
		$this->world = $source->getWorld();
		if ($radius <= 0) {
			throw new \InvalidArgumentException("Explosion radius must be greater than 0, got $radius");
		}
		$this->subChunkExplorer = new SubChunkExplorer($this->world);
	}

	public function getFireChance(): float {
		return $this->fireChance;
	}

	public function setFireChance(float $fireChance): void {
		$this->fireChance = $fireChance;
	}

	public function isIncendiary(): bool {
		return $this->fireChance > 0;
	}

	public function explodeA(): bool {
		if ($this->what instanceof Explosive && $this->what->isUnderwater()) {
			$this->doesDamage = false;
			return true;
		}
		if ($this->radius < 0.1) return false;

		$blockFactory = RuntimeBlockStateRegistry::getInstance();
		$mRays = $this->rays - 1;

		$incendiary = $this->fireChance > 0;
		if ($incendiary && empty($this->fireIgnitions)) {
			$this->fireIgnitions = new \Ds\Set();
		}

		for ($i = 0; $i < $this->rays; ++$i) {
			for ($j = 0; $j < $this->rays; ++$j) {
				for ($k = 0; $k < $this->rays; ++$k) {
					if ($i === 0 || $i === $mRays || $j === 0 || $j === $mRays || $k === 0 || $k === $mRays) {
						$shift = [($i / $mRays * 2 - 1), ($j / $mRays * 2 - 1), ($k / $mRays * 2 - 1)];
						$len = sqrt(array_sum(array_map(fn($x) => $x ** 2, $shift)));
						$shift = array_map(fn($x) => ($x / $len) * $this->stepLen, $shift);
						[$pointerX, $pointerY, $pointerZ] = [$this->source->x, $this->source->y, $this->source->z];

						for ($blastForce = $this->radius * (mt_rand(700, 1301) / 1000); $blastForce > 0; $blastForce -= $this->stepLen * 0.75) {
							$vBlockPos = [
								'x' => $pointerX >= ($x = (int)$pointerX) ? $x : $x - 1,
								'y' => $pointerY >= ($y = (int)$pointerY) ? $y : $y - 1,
								'z' => $pointerZ >= ($z = (int)$pointerZ) ? $z : $z - 1
							];

							$pointerX += $shift[0];
							$pointerY += $shift[1];
							$pointerZ += $shift[2];

							if ($this->subChunkExplorer->moveTo($vBlockPos['x'], $vBlockPos['y'], $vBlockPos['z']) === SubChunkExplorerStatus::INVALID) {
								continue;
							}

							$subChunk = $this->subChunkExplorer->currentSubChunk ?? throw new AssumptionFailedError("SubChunk should not be null here");
							$state = $subChunk->getBlockStateId($vBlockPos['x'] & 0xf, $vBlockPos['y'] & 0xf, $vBlockPos['z'] & 0xf);
							$blastResistance = $blockFactory->blastResistance[$state] ?? 0;

							$block = $this->world->getBlockAt($vBlockPos['x'], $vBlockPos['y'], $vBlockPos['z']);
							if ($block->getTypeId() !== VanillaBlocks::AIR()->getTypeId()) {
								$blastForce -= ($blastResistance / 5 + 0.3) * $this->stepLen;

								if ($blastForce > 0 && $block->getPosition()->y >= $this->minHeight) {
									if (!isset($this->affectedBlocks[World::blockHash(...array_values($vBlockPos))])) {
										foreach ($block->getAffectedBlocks() as $_affectedBlock) {
											$pos = $_affectedBlock->getPosition();
											$this->affectedBlocks[World::blockHash($pos->x, $pos->y, $pos->z)] = $_affectedBlock;
										}
									}
								}

								if ($incendiary && mt_rand() / mt_getrandmax() <= $this->fireChance) {
									$this->fireIgnitions->add($block);
								}
							}
						}
					}
				}
			}
		}

		return true;
	}

	public function explodeB(): bool {
		$source = (new Vector3($this->source->x, $this->source->y, $this->source->z))->floor();
		$yield = min(100, (1 / $this->radius) * 100);

		if ($this->what instanceof Entity) {
			$event = new EntityExplodeEvent($this->what, $this->source, $this->affectedBlocks, $yield, $this->fireIgnitions);

			$event->setIgnitions($this->fireIgnitions);
			$event->call();

			if ($event->isCancelled()) {
				return false;
			}

			$yield = $event->getYield();
			$this->affectedBlocks = $event->getBlockList();
		}elseif ($this->what instanceof Block) {
			$affectedBlocksSet = new \Ds\Set($this->affectedBlocks);
			$ignitionsSet = $this->fireIgnitions;

			$event = new BlockExplodeEvent(
				$this->what,
				$this->source,
				$this->affectedBlocks,
				$yield,
				$affectedBlocksSet,
				$ignitionsSet,
				$this->fireChance
			);

			$event->call();

			if ($event->isCancelled()) {
				return false;
			} else {
				$yield = $event->getYield();
				$this->affectedBlocks = $event->getAffectedBlocks()->toArray();
				$this->fireIgnitions = $event->getIgnitions();
			}
		}

		$explosionSize = $this->radius * 2;
		$boundingBox = new AxisAlignedBB(
			(int) floor($this->source->x - $explosionSize - 1),
			(int) floor($this->source->y - $explosionSize - 1),
			(int) floor($this->source->z - $explosionSize - 1),
			(int) ceil($this->source->x + $explosionSize + 1),
			(int) ceil($this->source->y + $explosionSize + 1),
			(int) ceil($this->source->z + $explosionSize + 1)
		);

		foreach ($this->world->getNearbyEntities($boundingBox, $this->what instanceof Entity ? $this->what : null) as $entity) {
			$distance = $entity->getPosition()->distance($this->source) / $explosionSize;
			$distance = min($distance, 1);
			$impact = max(0, (1 - $distance) * $this->getSeenPercent($this->source, $entity));

			if ($entity instanceof Player) {
				$netheritePieces = 0;
				foreach ($entity->getArmorInventory()->getContents() as $item) {
					if ($item instanceof TieredTool && $item->getTier() === ToolTier::NETHERITE) {
						$netheritePieces++;
					}
				}
				$netheriteReduction = 1 - (0.125 * $netheritePieces);
				$netheriteReduction = max(0.5, $netheriteReduction);

				$impact *= $netheriteReduction;

				if ($entity->isSneaking() && $entity->getInventory()->getItemInHand()->getTypeId() === ItemTypeIds::SHIELD) {
					$eyePos = $entity->getEyePos();
					$toExplosion = $this->source->subtractVector($eyePos);
					$direction = $eyePos->subtractVector($entity->getPosition());
					$angle = $direction->dot($toExplosion->normalize());

					if ($angle > 0.5) {
						$impact = 0;
					}
				}
			}
			$damage = $this->doesDamage ? max((int)(((($impact * $impact + $impact) / 2) * 5 * $explosionSize) + 1), 0) : 0;

			$event = match (true) {
				$this->what instanceof Entity => new EntityDamageByEntityEvent($this->what, $entity, EntityDamageEvent::CAUSE_ENTITY_EXPLOSION, $damage),
				$this->what instanceof Block => new EntityDamageByBlockEvent($this->what, $entity, EntityDamageEvent::CAUSE_BLOCK_EXPLOSION, $damage),
				default => new EntityDamageEvent($entity, EntityDamageEvent::CAUSE_BLOCK_EXPLOSION, $damage),
			};

			$entity->attack($event);
			if ($entity instanceof Player && $entity->getInventory()->getItemInHand()->getTypeId() === ItemTypeIds::SHIELD) {
				$motion = $entity->getPosition()->subtractVector($this->source)->normalize();
				if (!$event->isCancelled()) {
					$entity->setMotion($entity->getMotion()->addVector($motion->multiply($impact)));
				}
			}
		}

		$air = VanillaItems::AIR();
		$airBlock = VanillaBlocks::AIR();

		foreach ($this->affectedBlocks as $block) {
			$pos = $block->getPosition();

			foreach ($this->fireIgnitions as $fireBlock) {
				$firePos = $fireBlock->getPosition();
				$toIgnite = $this->world->getBlockAt($firePos->getX(), $firePos->getY(), $firePos->getZ());

				if ($toIgnite->getTypeId() === VanillaBlocks::AIR()->getTypeId() &&
					$toIgnite->getSide(side: Facing::UP)->isSolid()) {
					$this->world->setBlockAt($firePos->getX(), $firePos->getY(), $firePos->getZ(), VanillaBlocks::FIRE());
				}
			}

			if ($block instanceof TNT) {
				$block->ignite(mt_rand(10, 30));
			} else {
				if (mt_rand(0, 100) < $yield) {
					foreach ($block->getDrops($air) as $drop) {
						$this->world->dropItem($pos->add(0.5, 0.5, 0.5), $drop);
					}
				}

				if (($t = $this->world->getTileAt($pos->x, $pos->y, $pos->z)) !== null) {
					$t->onBlockDestroyed();
				}

				$this->world->setBlockAt($pos->x, $pos->y, $pos->z, $airBlock);
			}
		}

		$this->world->addParticle($source, new HugeExplodeSeedParticle());
		$this->world->addSound($source, new ExplodeSound());

		return true;
	}

	private function getSeenPercent(Vector3 $source, Entity $entity): float {
		$bb = $entity->getBoundingBox();

		if ($bb->isVectorInside($source)) {
			return 1.0;
		}

		$x = 1 / (($bb->maxX - $bb->minX) * 2 + 1);
		$y = 1 / (($bb->maxY- $bb->minY) * 2 + 1);
		$z = 1 / (($bb->maxZ - $bb->minZ) * 2 + 1);

		$xOffset = (1 - floor(1 / $x) * $x) / 2;
		$yOffset = (1 - floor(1 / $y) * $y) / 2;
		$zOffset = (1 - floor(1 / $z) * $z) / 2;

		$misses = 0;
		$total = 0;

		for ($i = 0; $i <= 1; $i += $x) {
			for ($j = 0; $j <= 1; $j += $y) {
				for ($k = 0; $k <= 1; $k += $z) {
					$target = new Vector3(
						$bb->minX + $i * ($bb->maxX - $bb->minX) + $xOffset,
						$bb->minY + $j * ($bb->maxY - $bb->minY) + $yOffset,
						$bb->minZ + $k * ($bb->maxZ - $bb->minZ) + $zOffset
					);

					if (!$this->raycast($source, $target)) {
						++$misses;
					}

					$total++;
				}
			}
		}

		return $total != 0 ? (float) $misses / (float) $total : 0.0;
	}

	private function raycast(Vector3 $start, Vector3 $end): bool {
		$current = new Vector3($start->getX(), $start->getY(), $start->getZ());
		$direction = $end->subtractVector($start)->normalize();

		$stepX = $this->sign($direction->getX());
		$stepY = $this->sign($direction->getY());
		$stepZ = $this->sign($direction->getZ());

		$tMaxX = $this->boundary($start->getX(), $direction->getX());
		$tMaxY = $this->boundary($start->getY(), $direction->getY());
		$tMaxZ = $this->boundary($start->getZ(), $direction->getZ());

		$tDeltaX = $direction->getX() == 0 ? 0 : $stepX / $direction->getX();
		$tDeltaY = $direction->getY() == 0 ? 0 : $stepY / $direction->getY();
		$tDeltaZ = $direction->getZ() == 0 ? 0 : $stepZ / $direction->getZ();

		$radius = $start->distance($end);

		while (true) {
			$block = $this->world->getBlock($current);

			if ($block->isSolid() && $block->calculateIntercept($current, $end) !== null) {
				return true;
			}

			if ($tMaxX < $tMaxY && $tMaxX < $tMaxZ) {
				if ($tMaxX > $radius) {
					break;
				}

				$current = new Vector3($current->getX() + $stepX, $current->getY(), $current->getZ());
				$tMaxX += $tDeltaX;
			} elseif ($tMaxY < $tMaxZ) {
				if ($tMaxY > $radius) {
					break;
				}

				$current = new Vector3($current->getX(), $current->getY() + $stepY, $current->getZ());
				$tMaxY += $tDeltaY;
			} else {
				if ($tMaxZ > $radius) {
					break;
				}

				$current = new Vector3($current->getX(), $current->getY(), $current->getZ() + $stepZ);
				$tMaxZ += $tDeltaZ;
			}
		}

		return false;
	}

	private function sign(float $d): float {
		if ($d > 0) {
			return 1;
		}

		if ($d < 0) {
			return -1;
		}

		return 0;
	}

	private function boundary(float $start, float $distance): float {
		if ($distance == 0) {
			return PHP_FLOAT_MAX;
		}

		if ($distance < 0) {
			$start = -$start;
			$distance = -$distance;

			if (floor($start) == $start) {
				return 0;
			}
		}

		return (1 - ($start - floor($start))) / $distance;
	}

	/**
	 * Устанавливает минимальную высоту, на которой взрыв может разрушать блоки
	 *
	 * @param float $minHeight минимальная координата Y
	 */
	public function setMinHeight(float $minHeight): void {
		$this->minHeight = $minHeight;
	}
}
