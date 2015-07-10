<?php

namespace onebone\npc;

use pocketmine\entity\Entity;
use pocketmine\level\Location;
use pocketmine\Server;
use pocketmine\network\protocol\AddPlayerPacket;
use pocketmine\network\protocol\MovePlayerPacket;
use pocketmine\network\protocol\RemovePlayerPacket;
use pocketmine\item\Item;
use pocketmine\Player;
use pocketmine\math\Vector2;

class WorksNPC{
	private $eid;
	private $pos, $yaw, $pitch;
	private $skin, $slim, $name;
	private $item, $meta, $work;
	
	public function __construct(Location $loc, $name, $skin, $slim, Item $item, NPCWork $work){
		$this->pos = $loc;
		$this->eid = Entity::$entityCount++;
		$this->skin = $skin;
		$this->slim = $slim;
		$this->name = $name;
		$this->item = $item->getID();
		$this->meta = $item->getDamage();
		$this->work = $work;
	}
	
	public function getName(){
		return $this->name;
	}

	public function setX($x){
		$this->pos->x = $x;
	}
	
	public function setY($y){
		$this->pos->y = $y;
	}
	
	public function setZ($z){
		$this->pos->z = $z;
	}
	
	public function setYaw($yaw){
		$this->pos->yaw = $yaw;
	}
	
	public function setPitch($pitch){
		$this->pos->pitch = $pitch;
	}
	
	public function getLevel(){
		return $this->pos->level;
	}
	
	public function getSkin(){
		return $this->skin;
	}
	
	public function setHoldingItem(Item $item){
		$this->item = $item->getId();
		$this->meta = $item->getDamage();
	}
	
	public function getId(){
		return $this->eid;
	}
	
	public function onInteract(Player $player){
		$this->work->work($player);
	}
	
	public function seePlayer(Player $target){
		$pk = new MovePlayerPacket();
		$pk->eid = $this->eid;
		if($this->pos->yaw === -1 and $target !== null){
			$xdiff = $target->x - $this->pos->x;
			$zdiff = $target->z - $this->pos->z;
			$angle = atan2($zdiff, $xdiff);
			$pk->yaw = (($angle * 180) / M_PI) - 90;
		}else{
			$pk->yaw = $this->pos->yaw;
		}
		if($this->pos->pitch === -1 and $target !== null){
			$ydiff = $target->y - $this->pos->y;
			
			$vec = new Vector2($this->pos->x, $this->pos->z);
			$dist = $vec->distance($target->x, $target->z);
			$angle = atan2($dist, $ydiff);
			$pk->pitch = (($angle * 180) / M_PI) - 90;
		}else{
			$pk->pitch = $this->pitch;
		}
		$pk->x = $this->pos->x;
		$pk->y = $this->pos->y + 1.62;
		$pk->z = $this->pos->z;
		$pk->bodyYaw = $pk->yaw;
		$pk->onGruond = 0;
		
		$target->dataPacket($pk);
	}
	
	public function spawnTo(Player $target){
		$pk = new AddPlayerPacket();
		$pk->clientID = $this->eid;
		$pk->username = $this->name;
		$pk->eid = $this->eid;
		$pk->x = $this->pos->x;
		$pk->y = $this->pos->y;
		$pk->z = $this->pos->z;
		if($this->pos->yaw === -1 and $target !== null){
			$xdiff = $target->x - $this->pos->x;
			$zdiff = $target->z - $this->pos->z;
			$angle = atan2($zdiff, $xdiff);
			$pk->yaw = (($angle * 180) / M_PI) - 90;
		}else{
			$pk->yaw = $this->pos->yaw;
		}
		if($this->pos->pitch === -1 and $target !== null){
			
		}else{
			$pk->pitch = $this->pos->pitch;
		}
		$pk->item = $this->item;
		$pk->meta = $this->meta;
		$pk->skin = $this->skin;
		$pk->slim = false;
		$pk->metadata = 
		[
			Entity::DATA_SHOW_NAMETAG => [
						Entity::DATA_TYPE_BYTE,
						1
				],
		];
		$target->dataPacket($pk);
	}
	
	public function remove(){
		$pk = new RemovePlayerPacket();
		$pk->eid = $this->eid;
		$pk->clientId = $this->eid;
		$players = $this->pos->level->getPlayers();
		foreach($players as $player){
			$player->dataPacket($pk);
		}
	}
	
	public function getSaveData(){
		return [
			$this->pos->x, $this->pos->y, $this->pos->z, $this->pos->level->getFolderName(),
			$this->pos->yaw, $this->pos->pitch,
			$this->eid, $this->item, $this->meta, $this->name, $this->slim
		];
	}
	
	public static function createNPC($data){
		return new WorksNPC(new Location($data[0], $data[1], $data[2], $data[4], $data[5], Server::getInstance()->getLevelByName($data[3])), $data[9], $data[6], $data[10], Item::get($data[7], $data[8]), $data[11]);
	}
}