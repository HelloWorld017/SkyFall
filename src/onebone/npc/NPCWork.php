<?php

namespace onebone\npc;

use pocketmine\Player;

interface NPCWork{

	public function work(Player $interactedPlayer);

}