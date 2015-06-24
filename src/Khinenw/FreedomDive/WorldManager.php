<?php

namespace Khinenw\FreedomDive;

use Khinenw\SandCanyon\NotPlacingFallingSand;
use pocketmine\block\Block;
use pocketmine\event\entity\EntitySpawnEvent;
use pocketmine\item\Item;
use pocketmine\level\particle\CriticalParticle;
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
	private $roundTick;

	const PLAYER_STATUS_ALIVE = 0;
	const PLAYER_STATUS_FALLEN = 1;

	const STATUS_INGAME = 0;
	const STATUS_NOT_STARTED = 1;
	const STATUS_PREPARING = 2;

	const SHOVEL = Item::WOODEN_SHOVEL;
	const RANGE = 3;

	public function __construct($worldFolderName, $serverId){
		$this->worldFolderName = $worldFolderName;
		$this->serverId = $serverId;
		$this->resetValues();
	}

	public function resetValues(){
		$this->currentStatus = self::STATUS_NOT_STARTED;

		$this->player = array();
		$this->removedBlocks = array();
		$this->aliveCount = 0;
		$this->innerTick = 0;
		$this->roundTick = 0;
	}

	public function startGame(){
		$this->currentStatus = self::STATUS_PREPARING;
	}

	//array of winner's player object
	public function winGame(array $winner){
		FreedomDive::getInstance()->notifyGameEnded($this->serverId, $winner);

		$this->finishGame();
	}

	private function finishGame(){
		$level = Server::getInstance()->getLevelByName($this->worldFolderName);

		foreach($this->removedBlocks as $blockData){
			$level->setBlock($blockData["pos"], $blockData["block"]);
		}

		$this->resetValues();
	}

	public function onPlayerDrown(Player $player){
		if($this->currentStatus === self::STATUS_INGAME) {
			$this->player[$player->getName()]["status"] = self::PLAYER_STATUS_FALLEN;
			$this->broadcastMessageForPlayers(FreedomDive::getTranslation("PLAYER_FALLEN", $player->getName()));
			$this->playerCountChange();
		}else{
			$player->teleport(Server::getInstance()->getLevelByName($this->worldFolderName)->getSpawnLocation());
		}
	}

	public function playerCountChange(){
		$alive = array();
		$playerCount = 0;

		foreach($this->player as $playerName => $playerData){
			$playerCount++;
			if($playerData["status"] === self::PLAYER_STATUS_ALIVE){
				array_push($alive, $playerName);
			}
		}

		switch($this->currentStatus){
			case self::STATUS_INGAME:
					if(count($alive) <= 1){
						$this->winGame($alive);
					}
				break;
			case self::STATUS_NOT_STARTED:
				if($playerCount >= FreedomDive::getInstance()->getConfiguration("NEED_PLAYERS")){
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
			//$this->removeBlockWithAnim($block);
			$this->removeBlockWithRange($block);
		}
	}

	public function broadcastMessageForPlayers($message){
		foreach($this->player as $playerData){
			$playerData["player"]->sendMessage($message);
		}
	}

	public function removeBlockWithRange(Position $pos){
		$halfRange = (self::RANGE - 1) / 2;
		for($x = 0; $x <= self::RANGE; $x++){
			for($z = 0; $z <= self::RANGE; $z++){
				$this->removeBlockWithAnim($pos->add($halfRange - $x, 0, $halfRange - $z));
			}
		}
	}

	public function removeBlockWithAnim(Vector3 $pos){
		$level = Server::getInstance()->getLevelByName($this->worldFolderName);
		$originalBlock = $level->getBlock($pos);

		for($i = 0; $i < 50; $i++){
			$level->addParticle(new CriticalParticle($pos->add(mt_rand(-1, 1), mt_rand(-1, 1), mt_rand(-1, 1))));
		}

		if($originalBlock->getId() === Block::AIR){
			return;
		}

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
		$sand = new NotPlacingFallingSand($level->getChunk($pos->getX() >> 4, $pos->getZ() >> 4), $nbtTag);

		$sand->setMotion(new Vector3(0, 0.55, 0));

		Server::getInstance()->getPluginManager()->callEvent(new EntitySpawnEvent($sand));
		$level->addEntity($sand);

		$level->setBlock($pos, Block::get(0));

		$sand->spawnToAll();
	}

	public function onTick(){
		$this->innerTick++;
		$this->roundTick++;

		switch($this->currentStatus){
			case self::STATUS_NOT_STARTED:
				break;
			case self::STATUS_PREPARING:
				if($this->roundTick >= FreedomDive::getInstance()->getConfiguration("PREPARATION_TERM")){
					$this->setIngame();
				}
				break;
			case self::STATUS_INGAME:
				if($this->roundTick >= FreedomDive::getInstance()->getConfiguration("GAME_TERM")){
					$this->winGame([]);
				}
			break;
		}
	}

	public function setIngame(){
		$this->currentStatus = self::STATUS_INGAME;
		$this->broadcastMessageForPlayers(FreedomDive::getTranslation("GAME_STARTED"));
	}
}
