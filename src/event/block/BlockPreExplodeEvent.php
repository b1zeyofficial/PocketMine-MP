<?php

declare(strict_types=1);

namespace pocketmine\event\block;

use pocketmine\block\Block;
use pocketmine\event\CancellableTrait;
use pocketmine\player\Player;
use pocketmine\event\Cancellable;

/**
 * Class BlockExplosionPrimeEvent
 * Event triggered before a block explosion, allowing modifications to the explosion force, block destruction, and fire chances.
 * This event is used to customize the behavior of explosions before they happen.
 *
 * @see BlockExplodeEvent
 *
 * @phpstan-extends BlockEvent<Block>
 */
class BlockPreExplodeEvent extends BlockEvent implements Cancellable {
	use CancellableTrait;

	public function __construct(
		Block $block,
		private readonly ?Player $player = null,
		private float $force = 4.0,
		private float $fireChance = 1.0,
		private bool $blockBreaking = true
	) {
		parent::__construct($block);
	}

	/**
	 * Get Explosion Power
	 * @return float
	 */
	public function getForce(): float
	{
		return $this->force;
	}

	/**
	 * Set explosion strength
	 * @param float $force
	 */
	public function setForce(float $force): void
	{
		$this->force = $force;
	}

	/**
	 * Checking whether the block will collapse
	 * @return bool
	 */
	public function isBlockBreaking(): bool
	{
		return $this->blockBreaking;
	}

	/**
	 * Set whether a block will be destroyed
	 * @param bool $blockBreaking
	 */
	public function setBlockBreaking(bool $blockBreaking): void
	{
		$this->blockBreaking = $blockBreaking;
	}

	/**
	 * Checking if there will be a fire
	 * @return bool
	 */
	public function isIncendiary(): bool
	{
		return $this->fireChance > 0;
	}

	/**
	 * Establish the probability of fire
	 * @param bool $incendiary
	 */
	public function setIncendiary(bool $incendiary): void
	{
		if (!$incendiary) {
			$this->fireChance = 0;
		} else {
			if ($this->fireChance <= 0) {
				$this->fireChance = 1.0 / 3.0;
			}
		}
	}

	/**
	 * Get the probability of fire
	 * @return float
	 */
	public function getFireChance(): float
	{
		return $this->fireChance;
	}

	/**
	 * Establish the probability of fire
	 * @param float $fireChance
	 */
	public function setFireChance(float $fireChance): void
	{
		$this->fireChance = $fireChance;
	}

	/**
	 * Get the player if the event was caused by him
	 * @return Player|null
	 */
	public function getPlayer(): ?Player
	{
		return $this->player;
	}
}
