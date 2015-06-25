<?php

namespace Khinenw\FreedomDive\task;

use Khinenw\FreedomDive\FreedomDive;
use pocketmine\scheduler\PluginTask;

class TickTask extends PluginTask{
	public function __construct(FreedomDive $dive){
		parent::__construct($dive);
	}

	public function onRun($currentTick){
		$this->getOwner()->tick();
	}
}