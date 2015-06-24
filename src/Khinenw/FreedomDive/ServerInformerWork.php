<?php

namespace Khinenw\FreedomDive;

use onebone\npc\NPCWork;
use pocketmine\Player;
use pocketmine\Server;

class ServerInformerWork implements NPCWork{

	private $worldName;

	public function __construct($worldName){
		$this->worldName = $worldName;
	}

	public function work(Player $interactedPlayer){
		if(FreedomDive::getInstance()->getW)
		$interactedPlayer->teleport(Server::getInstance()->getLevelByName($this->worldName)->getSpawnLocation());
	}
}