<?php

namespace Khinenw\FreedomDive;

use Khinenw\SandCanyon\NotPlacingFallingSand;
use pocketmine\block\Block;
use pocketmine\event\entity\EntitySpawnEvent;
use pocketmine\item\Item;
use pocketmine\level\Position;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\Byte;
use pocketmine\nbt\tag\Compound;
use pocketmine\nbt\tag\Double;
use pocketmine\nbt\tag\Enum;
use pocketmine\nbt\tag\Float;
use pocketmine\nbt\tag\Int;
use pocketmine\Player;
use pocketmine\Server;
use pocketmine\utils\TextFormat;

class WorldManager {
	private $worldFolderName;
	private $serverId;
	private $player;
	private $removedBlocks;
	private $currentStatus;
	private $aliveCount;
	private $innerTick;

	const PLAYER_STATUS_ALIVE = 0;
	const PLAYER_STATUS_FALLEN = 1;

	const STATUS_INGAME = 0;
	const STATUS_PREPARING = 1;
	const STATUS_NOT_STARTED = 2;

	const SHOVEL = Item::WOODEN_SHOVEL;

	public function __construct($worldFolderName, $serverId){
		$this->worldFolderName = $worldFolderName;
		$this->serverId = $serverId;
		$this->player = array();
		$this->removedBlocks = array();
		$this->currentStatus = self::STATUS_NOT_STARTED;
		$this->aliveCount = 0;
		$this->innerTick = 0;
	}

	public function startGame(){

	}

	//array of winner's player object
	public function winGame(array $winner){
		if(count($winner) === 0) {
			Server::getInstance()->broadcastMessage(TextFormat::AQUA.FreedomDive::getTranslation("FINISH_NO_WINNER", $this->serverId));
		}else{
			$winnerText = "";

			foreach($winner as $winnerName){
				$winnerText .= "$winnerName, ";
			}

			$winnerText = substr($winnerText, 0, -2);

			Server::getInstance()->broadcastMessage(TextFormat::AQUA.FreedomDive::getTranslation("FINISH_WINNER", $this->serverId, $winnerText));
		}

		$this->finishGame();
	}

	private function finishGame(){
		$this->currentStatus = self::STATUS_NOT_STARTED;
		$level = Server::getInstance()->getLevelByName($this->worldFolderName);

		foreach($this->removedBlocks as $blockData){
			$level->setBlock($blockData["pos"], $blockData["block"]);
		}

		$this->player = array();
		$this->removedBlocks = array();
		$this->currentStatus = self::STATUS_NOT_STARTED;
		$this->aliveCount = 0;
		$this->innerTick = 0;
	}

	public function onPlayerDrown(Player $player){
		$this->player[$player->getName()]["status"] = self::PLAYER_STATUS_FALLEN;
		$this->playerCountChange();
	}

	public function playerCountChange(){
		$alive = array();
		$playerCount = 0;

		foreach($this->player as $playerData){
			$playerCount++;
			if($playerData["status"] === self::PLAYER_STATUS_ALIVE){
				array_push($alive, $playerData["player"]);
			}
		}

		switch($this->currentStatus){
			case self::STATUS_INGAME:
					if(count($alive) <= 1){
						$this->winGame($alive);
					}
				break;
			case self::STATUS_PREPARING: break;
			case self::STATUS_NOT_STARTED:
				if($playerCount >= 2){
					$this->startGame();
				}
				break;
		}
	}

	public function onPlayerMoveToWorld(Player $player){
		$this->player[$player->getName()] = [
			"player" => $player,
			"status" => self::PLAYER_STATUS_ALIVE
		];
		$player->getInventory()->addItem(Item::get(Item::WOODEN_SHOVEL));
		$this->playerCountChange();
	}

	public function onPlayerMoveToAnotherWorld(Player $player, $anotherWorldFolderName){
		$this->playerOut($player);
	}

	public function onPlayerQuit(Player $player){
		$this->playerOut($player);
	}

	private function playerOut(Player $player){
		$player->getInventory()->removeItem(Item::get(Item::WOODEN_SHOVEL));
		$isFallen = $this->player[$player->getName()] === self::PLAYER_STATUS_FALLEN;
		unset($this->player[$player->getName()]);

		if(!$isFallen) {
			$this->broadcastMessageForPlayers(TextFormat::RED . FreedomDive::getTranslation("PLAYER_OUT", $player->getName()));
			$this->playerCountChange();
		}
	}

	public function onPlayerInteractWithShovel(Player $player, Block $block){
		if($this->currentStatus == self::STATUS_INGAME){
			$this->removeBlockWithAnim($block);
		}
	}

	public function broadcastMessageForPlayers($message){
		foreach($this->player as $playerData){
			$playerData["player"]->sendMessage($message);
		}
	}

	public function removeBlockWithAnim(Position $pos){
		$originalBlock = $pos->getLevel()->getBlock($pos);

		array_push($this->removedBlocks, [
			"pos" => $pos,
			"originalBlock" => $originalBlock
		]);

		$nbtTag = new Compound("", [
			"Pos" => new Enum("Pos", [
				new Double("", $pos->getX() + 0.5),
				new Double("", $pos->getY()),
				new Double("", $pos->getZ() + 0.5)]),

			"Rotation" => new Enum("Rotation", [
				new Float("", 0),
				new Float("", 0)]),

			"TileID" => new Int("TileID", $originalBlock->getId()),

			"Data" => new Byte("Data", $originalBlock->getDamage())
		]);
		$sand = new NotPlacingFallingSand($pos->getLevel()->getChunk($pos->getX() >> 4, $pos->getZ() >> 4), $nbtTag);

		$sand->setMotion(new Vector3(0, 0.55, 0));

		Server::getInstance()->getPluginManager()->callEvent(new EntitySpawnEvent($sand));
		$pos->getLevel()->addEntity($sand);

		$pos->getLevel()->setBlock($pos, Block::get(0));

		$sand->spawnToAll();
	}
}
