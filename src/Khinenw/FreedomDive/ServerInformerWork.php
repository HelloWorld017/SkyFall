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
		if(FreedomDive::getInstance()->getWorldManagerByWorldFolderName($this->worldName)->canJoinGame()) {
			$interactedPlayer->teleport(Server::getInstance()->getLevelByName($this->worldName)->getSpawnLocation());
			FreedomDive::getInstance()->getWorldManagerByWorldFolderName($this->worldName)->onPlayerMoveToWorld($interactedPlayer);
		}else{
			$interactedPlayer->sendMessage(FreedomDive::getTranslation("CANNOT_JOIN"));
		}
	}
}