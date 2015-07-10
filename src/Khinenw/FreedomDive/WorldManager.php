<?php

namespace Khinenw\FreedomDive;

use pocketmine\block\Block;
use pocketmine\entity\Effect;
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
	public $player;
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
	const RANGE = 2;

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
		$this->roundTick = 0;
		foreach($this->player as $playerData){
			$playerData["player"]->getInventory()->addItem(Item::get(self::SHOVEL));
			$playerData["player"]->addEffect(Effect::getEffect(Effect::JUMP)->setAmplifier(3)->setDuration(36000));
			$playerData["player"]->addEffect(Effect::getEffect(Effect::SPEED)->setAmplifier(3)->setDuration(36000));
		}
	}

	//array of winner's player object
	public function winGame(array $winner){
		FreedomDive::getInstance()->notifyGameEnded($this->serverId, $winner);

		$this->finishGame();
	}

	private function finishGame(){

		$dive = FreedomDive::getInstance();
		$defLev = Server::getInstance()->getDefaultLevel();
		$defPos = $defLev->getSpawnLocation();
		$this->currentStatus = self::STATUS_NOT_STARTED;

		foreach($this->player as $playerData){
			$dive->setPlayerWorld($playerData["player"]->getName(), $defLev->getFolderName());
			$playerData["player"]->teleport($defPos);
			$this->playerOut($playerData["player"], true);
		}

		$level = Server::getInstance()->getLevelByName($this->worldFolderName);

		$this->regenerateBlocks();

		$this->resetValues();
	}

	public function regenerateBlocks(){
		Server::getInstance()->broadcastMessage(TextFormat::AQUA."[FREEDOM DiVE] Regenerating World!");
		$level = Server::getInstance()->getLevelByName($this->worldFolderName);

		foreach($this->removedBlocks as $blockData){
			$level->setBlock($blockData["pos"], $blockData["block"]);
		}

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

		$this->aliveCount = count($alive);

		switch($this->currentStatus){
			case self::STATUS_INGAME:
					if($this->aliveCount <= 1){
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
		FreedomDive::getInstance()->setPlayerWorld($player->getName(), $this->worldFolderName);
		$this->player[$player->getName()] = [
			"player" => $player,
			"status" => self::PLAYER_STATUS_ALIVE
		];
		$this->playerCountChange();
	}

	public function onPlayerMoveToAnotherWorld(Player $player, $anotherWorldFolderName){
		FreedomDive::getInstance()->setPlayerWorld($player->getName(), $anotherWorldFolderName);
		$this->playerOut($player);
	}

	public function onPlayerQuit(Player $player){
		$this->playerOut($player);
	}

	public function hasPlayer($playerName){
		return isset($this->player[$playerName]);
	}

	private function playerOut(Player $player, $noNotification = false){
		$player->getInventory()->removeItem(Item::get(self::SHOVEL));
		$player->removeEffect(Effect::SPEED);
		$player->removeEffect(Effect::JUMP);

		if(!isset($this->player[$player->getName()])){
			return;
		}

		$isFallen = $this->player[$player->getName()]["status"] === self::PLAYER_STATUS_FALLEN;
		unset($this->player[$player->getName()]);

		if(!$isFallen && !$noNotification){
			$this->broadcastMessageForPlayers(TextFormat::RED . FreedomDive::getTranslation("PLAYER_OUT", $player->getName()));
			$this->playerCountChange();
		}
	}

	public function onPlayerInteractWithShovel(Player $player, Block $block){
		if($this->currentStatus == self::STATUS_INGAME){
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

		for($i = 0; $i < 25; $i++){
			$level->addParticle(new CriticalParticle($pos->add(mt_rand(-1, 1), mt_rand(-1, 1), mt_rand(-1, 1))));
		}

		if($originalBlock->getId() === Block::AIR){
			return;
		}

		array_push($this->removedBlocks, [
			"pos" => $pos,
			"block" => $originalBlock
		]);

		if(FreedomDive::getInstance()->getConfiguration("IS_BEAUTIFUL_ANIM")){
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
			$sand->spawnToAll();
		}

		$level->setBlock($pos, Block::get(0));
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
		$this->roundTick = 0;
	}

	public function getTip(){
		switch($this->currentStatus){
			case self::STATUS_NOT_STARTED:
				return TextFormat::GREEN.FreedomDive::getTranslation("WAITING_FOR_PLAYERS", count($this->player), FreedomDive::getInstance()->getConfiguration("NEED_PLAYERS"));

			case self::STATUS_PREPARING:
				$time = (FreedomDive::getInstance()->getConfiguration("PREPARATION_TERM") - $this->roundTick) / 20;
				return TextFormat::GREEN.FreedomDive::getTranslation("PREPARING", round($time / 60), $time % 60);

			case self::STATUS_INGAME:
				$time = (FreedomDive::getInstance()->getConfiguration("GAME_TERM") - $this->roundTick) / 20;
				return TextFormat::AQUA.FreedomDive::getTranslation("GAME_MESSAGE", round($time / 60), $time % 60, $this->aliveCount);

			default: return "";
		}
	}

	public function canJoinGame(){
		return $this->currentStatus !== self::STATUS_INGAME;
	}
}
