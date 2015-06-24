<?php

namespace Khinenw\FreedomDive;

use onebone\npc\WorksNPC;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\item\Item;
use pocketmine\level\Location;
use pocketmine\network\protocol\InteractPacket;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;

class FreedomDive extends PluginBase implements Listener{

	private $worlds, $players, $config, $npcs, $wins;

	private static $translations;

	private $skinFile;

	private static $mi;

	public function onEnable(){
		self::$mi = $this;
		@mkdir($this->getDataFolder());

		if(is_file($this->getDataFolder()."translation_en.yml")){
				file_put_contents($this->getDataFolder()."translation_en.yml", stream_get_contents($this->getResource("translation_en.yml")));
		}

		if(is_file($this->getDataFolder()."translation_ko.yml")){
			file_put_contents($this->getDataFolder()."translation_ko.yml", stream_get_contents($this->getResource("translation_ko.yml")));
		}

		if(is_file($this->getDataFolder()."config.yml")){
			file_put_contents($this->getDataFolder()."config.yml", stream_get_contents($this->getResource("config.yml")));
		}

		if(!is_file($this->getDataFolder()."skin.png")){
			file_put_contents($this->getDataFolder()."skin.png", stream_get_contents($this->getResource("skin.png")));
		}

		$this->config = (new Config($this->getDataFolder()."config.yml", Config::YAML))->getAll();
		$this->wins = (new Config($this->getDataFolder()."config.yml", Config::YAML))->getAll();

		$lang = "en";
		if(isset($this->config["language"])){
			if(is_file($this->getDataFolder()."translation_$lang.yml")){
				$lang = $this->config["language"];
			}
		}

		self::$translations = (new Config($this->getDataFolder()."translation_$lang.yml", Config::YAML))->getAll();

		$this->npcs = array();
		$this->players = array();
		$this->worlds = array();
		$this->skinFile = file_get_contents($this->getDataFolder()."skin.png");

		$i = 0;
		foreach($this->config["worlds"] as $worldFolderName => $worldData){
			$i++;
			$npcData = $worldData["npc"];
			$npc = new WorksNPC(
				new Location($npcData["x"], $npcData["y"], $npcData["z"], -1, -1, $this->getServer()->getLevelByName($npcData["world"])),
				self::getTranslation("LEVEL_INFORMATOR", $i),
				$this->skinFile,
				false,
				Item::get(Item::AIR),
				new ServerInformerWork($worldFolderName)
			);
			$this->npcs[$npc->getId()] = $npc;

			$this->worlds[$worldFolderName] = [
				"manager" => new WorldManager($worldFolderName, $i),
				"serverId" => $i,
				"npc" => $npc
			];
		}
	}

	/**
	 * @return FreedomDive
	 */
	public static function getInstance(){
		return self::$mi;
	}

	public function onDisable(){

	}

	public function onPlayerJoin(PlayerJoinEvent $event){
		if(isset($this->players[$event->getPlayer()->getName()])){
			$this->players[$event->getPlayer()->getName()] = $this->getServer()->getDefaultLevel()->getFolderName();
		}
	}

	public function onPlayerQuit(PlayerQuitEvent $event){
		if(!isset($this->players[$event->getPlayer()->getName()])){
			return;
		}

		$this->getWorldManagerByPlayerName($event->getPlayer()->getName())->onPlayerQuit($event->getPlayer());
	}

	public function onMoveEvent(PlayerMoveEvent $event){
		$player = $event->getPlayer();

		if(($fromLevel = $event->getFrom()->getLevel()->getFolderName()) !== ($toLevel = $event->getTo()->getLevel()->getFolderName())){
			if(isset($this->worlds[$toLevel])){
				$this->players[$event->getPlayer()->getName()] = $toLevel;
				$this->getWorldManagerByWorldFolderName($toLevel)->onPlayerMoveToWorld($event->getPlayer());
			}

			if(isset($this->worlds[$fromLevel])){
				if($this->players[$event->getPlayer()->getName()] === $fromLevel){
					$this->players[$event->getPlayer()->getName()] = $this->getServer()->getDefaultLevel()->getFolderName();
				}

				$this->getWorldManagerByWorldFolderName($fromLevel)->onPlayerMoveToAnotherWorld($event->getPlayer(), $toLevel);
			}

			foreach($this->npcs as $npc){
				if($npc->getLevel()->getFolderName() === $toLevel){
					$npc->spawnTo($player);
				}
			}
		}

		foreach($this->npcs as $npc){
			if($npc->getLevel()->getFolderName() === $event->getPlayer()->getLevel()->getFolderName()){
				$npc->seePlayer($player);
			}
		}
	}

	public function onPacketReceived(DataPacketReceiveEvent $event){
		$pk = $event->getPacket();
		if($pk instanceof InteractPacket){
			if(isset($this->npcs[$pk->target])){
				$this->getNPCByEID($pk->target)->onInteract($event->getPlayer());
			}
		}
	}

	public function notifyGameEnded($serverId, array $winner){
		if(count($winner) === 0) {
			$this->getServer()->broadcastMessage(TextFormat::AQUA.FreedomDive::getTranslation("FINISH_NO_WINNER", $serverId));
		}else{
			$winnerText = "";

			foreach($winner as $winnerName){
				if(isset($this->wins[$winnerName])){
					$this->wins[$winnerName]++;
				}else{
					$this->wins[$winnerName] = 0;
				}

				$winnerText .= "$winnerName, ";
			}

			$winnerText = substr($winnerText, 0, -2);

			$this->getServer()->broadcastMessage(TextFormat::AQUA.FreedomDive::getTranslation("FINISH_WINNER", $serverId, $winnerText));
		}
	}

	/**
	 * @param string $playerName name of player
	 * @return WorldManager
	 */
	public function getWorldManagerByPlayerName($playerName){
		return $this->worlds[$this->players[$playerName]]["manager"];
	}

	/**
	 * @param string $worldFolderName foldername of the world
	 * @return WorldManager
	 */
	public function getWorldManagerByWorldFolderName($worldFolderName){
		return $this->worlds[$worldFolderName]["manager"];
	}

	/**
	 * @param int $eid Entity ID of the NPC
	 * @return WorksNPC
	 */
	public function getNPCByEID($eid){
		return $this->npcs[$eid];
	}

	public function getConfiguration($key){
		return $this->config[$key];
	}

	public static function getTranslation($key, ...$params){
		return $key;
	}
}
