<?php

declare(strict_types=1);

namespace pocketmine\event\block;

use pocketmine\block\Block;
use pocketmine\event\Cancellable;
use pocketmine\event\CancellableTrait;
use pocketmine\utils\Utils;
use pocketmine\world\Position;

/**
 * Class BlockExplodeEvent
 * Event triggered when a block explodes (e.g., a bed in the Nether).
 * This event is used to handle the explosion of blocks and customize their behavior.
 *
 * @see BlockPreExplodeEvent
 *
 * @phpstan-extends BlockEvent<Block>
 */
class BlockExplodeEvent extends BlockEvent implements Cancellable {
	use CancellableTrait;

	/**
	 * Constructor for the BlockExplodeEvent class.
	 *
	 * @param Block $block The block that exploded
	 * @param Position $position The position of the explosion
	 * @param Block[] $blocks The list of blocks affected by the explosion
	 * @param float $yield The explosion yield (intensity)
	 */
	public function __construct(
		Block $block,
		protected Position $position,
		protected array $blocks,
		protected float $yield,
		private \Ds\Set $affectedBlocks,
		private \Ds\Set $ignitions,
		protected float $fireChance
	) {
		parent::__construct($block);

		if($yield < 0.0 || $yield > 100.0){
			throw new \InvalidArgumentException("Yield must be in range 0.0 - 100.0");
		}
	}

	/**
	 * Get the position where the explosion occurred.
	 *
	 * @return Position The position of the explosion
	 */
	public function getPosition(): Position {
		return $this->position;
	}

	/**
	 * Get the list of blocks affected by the explosion.
	 *
	 * @return Block[] List of blocks affected by the explosion
	 */
	public function getBlockList(): array {
		return $this->blocks;
	}

	/**
	 * Set the list of blocks affected by the explosion.
	 *
	 * @param Block[] $blocks The blocks to set as affected by the explosion
	 */
	public function setBlockList(array $blocks) : void {
		Utils::validateArrayValueType($blocks, function(Block $_) : void {});
		$this->blocks = $blocks;
	}

	/**
	 * Get the explosion yield (intensity).
	 *
	 * @return float The intensity of the explosion
	 */
	public function getYield(): float {
		return $this->yield;
	}

	/**
	 * Set the explosion yield (intensity).
	 *
	 * @param float $yield The intensity of the explosion (0-100)
	 * @throws \InvalidArgumentException If the yield is not within the valid range
	 */
	public function setYield(float $yield) : void {
		if($yield < 0.0 || $yield > 100.0){
			throw new \InvalidArgumentException("Yield must be in range 0.0 - 100.0");
		}
		$this->yield = $yield;
	}

	/**
	 * Get the set of affected blocks for fire ignitions
	 *
	 * @return \Ds\Set A set of blocks that are affected by fire ignitions
	 */
	public function getAffectedBlocks(): \Ds\Set {
		return $this->affectedBlocks;
	}

	/**
	 * Set the set of blocks affected by fire ignitions
	 *
	 * @param \Ds\Set $blocks The set of blocks to be affected by fire ignitions
	 */
	public function setAffectedBlocks(\Ds\Set $blocks): void {
		$this->affectedBlocks = $blocks;
	}

	/**
	 * Get the set of blocks that can be ignited by the explosion
	 *
	 * @return \Ds\Set A set of blocks that are ignited by the explosion
	 */
	public function getIgnitions(): \Ds\Set {
		return $this->ignitions;
	}

	/**
	 * Set the set of blocks that can be ignited by the explosion
	 *
	 * @param \Ds\Set $ignitions The set of blocks to set as ignited
	 */
	public function setIgnitions(\Ds\Set $ignitions): void {
		$this->ignitions = $ignitions;
	}

	/**
	 * Get the fire chance of the explosion
	 *
	 * @return float The fire chance (probability) of the explosion
	 */
	public function getFireChance(): float {
		return $this->fireChance;
	}

	/**
	 * Set the fire chance of the explosion
	 *
	 * @param float $fireChance The fire chance to set
	 */
	public function setFireChance(float $fireChance): void {
		$this->fireChance = $fireChance;
	}
}