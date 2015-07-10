<?php

namespace Khinenw\FreedomDive;

use Khinenw\FreedomDive\task\SendMessageTask;
use Khinenw\FreedomDive\task\TickTask;
use onebone\npc\WorksNPC;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerRespawnEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\item\Item;
use pocketmine\level\Location;
use pocketmine\network\protocol\InteractPacket;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;

class FreedomDive extends PluginBase implements Listener{

	private $worlds, $players, $config, $npcs, $wins;

	private static $translations;

	private $skinFile = false;

	private static $mi;

	public function onEnable(){
		self::$mi = $this;

		@mkdir($this->getDataFolder());

		$this->pushFile("translation_en.yml");
		$this->pushFile("translation_ko.yml");
		$this->pushFile("config.yml");

		$this->config = (new Config($this->getDataFolder()."config.yml", Config::YAML))->getAll();
		$this->wins = (new Config($this->getDataFolder()."wins.yml", Config::YAML))->getAll();

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
		if(is_file($this->getDataFolder()."npc.skin")){
			$this->skinFile = unserialize(file_get_contents($this->getDataFolder()."npc.skin"));
		}

		if(!isset($this->config["worlds"])){
			$this->config["worlds"] = [];
		}

		$i = 0;
		foreach($this->config["worlds"] as $worldFolderName => $worldData){
			$this->getLogger()->info(TextFormat::GREEN."Creating NPC for $worldFolderName");
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

		$this->getServer()->getScheduler()->scheduleRepeatingTask(new TickTask($this), 1);
		$this->getServer()->getScheduler()->scheduleRepeatingTask(new SendMessageTask($this), 1);
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
	}

	public function pushFile($fileName){
		if(!is_file($this->getDataFolder().$fileName)){
			$res = $this->getResource($fileName);
			file_put_contents($this->getDataFolder().$fileName, stream_get_contents($res));
			fclose($res);
		}
	}

	/**
	 * @return FreedomDive
	 */
	public static function getInstance(){
		return self::$mi;
	}

	public function onDisable(){
		foreach($this->worlds as $worldData){
			$worldData["manager"]->regenerateBlocks();
		}

	}

	public function onCommand(CommandSender $sender, Command $command, $label, array $args){
		switch($command->getName()){
			case "gamelevel":
				if(count($args) < 1){
					return false;
				}

				if(!($sender instanceof Player)){
					$sender->sendMessage(TextFormat::RED.self::getTranslation("MUST_INGAME"));
					return true;
				}

				if($this->getServer()->getLevelByName($args[0]) === null){
					$sender->sendMessage(TextFormat::RED.self::getTranslation("UNKNOWN_LEVEL"));
					return true;
				}

				if($this->skinFile === false){
					file_put_contents($this->getDataFolder()."npc.skin", serialize($sender->getSkinData()));
				}

				$this->config["worlds"][$args[0]] = [
					"npc" => [
						"x" => $sender->getX(),
						"y" => $sender->getY(),
						"z" => $sender->getZ(),
						"world" => $sender->getLevel()->getFolderName(),
					]
				];

				$conf = (new Config($this->getDataFolder()."config.yml", Config::YAML));
				$conf->setAll($this->config);
				$conf->save();

				$sender->sendMessage(TextFormat::RED.self::getTranslation("PLEASE_RESTART_SERVER"));
				$this->getServer()->getPluginManager()->disablePlugin($this);
				break;

			case "rank":
				$text = TextFormat::AQUA."=========".self::getTranslation("RANK")."=========".TextFormat::YELLOW;

				foreach($this->wins as $winner => $count){
					$text .= "\n".$winner." : ".$count;
				}

				$sender->sendMessage($text);
				break;

		}
		return true;
	}

	public function onPlayerDamage(EntityDamageEvent $event){
		$player = $event->getEntity();
		if(!$player instanceof Player)return;

		if($this->getWorldManagerByPlayerName($player->getName()) !== null){
			$event->setCancelled();
		}
	}

	public function onPlayerRespawn(PlayerRespawnEvent $event){
		if(isset($this->players[$event->getPlayer()->getName()])){
			if(($manager = $this->getWorldManagerByPlayerName($event->getPlayer()->getName())) !== null && $manager->hasPlayer($event->getPlayer()->getName())){
				$this->getWorldManagerByPlayerName($event->getPlayer()->getName())->onPlayerMoveToAnotherWorld($event->getPlayer(), $this->getServer()->getDefaultLevel()->getFolderName());
			}
		}

		foreach($this->npcs as $npc){
			if($npc->getLevel()->getFolderName() === $this->getServer()->getDefaultLevel()->getFolderName()){
				$npc->spawnTo($event->getPlayer());
			}
		}

		$event->setRespawnPosition($this->getServer()->getDefaultLevel()->getSpawnLocation());
	}

	public function onPlayerJoin(PlayerJoinEvent $event){
		foreach($this->npcs as $npc){
			if($npc->getLevel()->getFolderName() === $event->getPlayer()->getLevel()->getFolderName()){
				$npc->spawnTo($event->getPlayer());
			}
		}

		if(!isset($this->players[$event->getPlayer()->getName()])){
			$this->players[$event->getPlayer()->getName()] = $this->getServer()->getDefaultLevel()->getFolderName();
		}
	}

	public function onPlayerQuit(PlayerQuitEvent $event){
		if(!isset($this->players[$event->getPlayer()->getName()])){
			return;
		}

		$manager = $this->getWorldManagerByPlayerName($event->getPlayer()->getName());
		if($manager !== null) $manager->onPlayerQuit($event->getPlayer());
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

		if(isset($this->worlds[$toLevel])){
			if($event->getTo()->getY() <= $this->getConfiguration("MIN_Y")){
				$this->worlds[$toLevel]["manager"]->onPlayerDrown($event->getPlayer());
			}
		}

		foreach($this->npcs as $npc){
			if($npc->getLevel()->getFolderName() === $event->getPlayer()->getLevel()->getFolderName()){
				$npc->seePlayer($player);
			}
		}
	}

	public function onBlockPlace(BlockPlaceEvent $event){
		if(isset($this->worlds[$event->getBlock()->getLevel()->getFolderName()])){
			$event->setCancelled();
		}
	}

	public function onBlockBreak(BlockBreakEvent $event){
		if(isset($this->worlds[$event->getBlock()->getLevel()->getFolderName()])){
			$event->setCancelled();
		}
	}

	public function onPlayerInteract(PlayerInteractEvent $event){
		if(($event->getItem()->getId() === WorldManager::SHOVEL) && isset($this->worlds[$event->getBlock()->getLevel()->getFolderName()])){
			$this->worlds[$event->getBlock()->getLevel()->getFolderName()]["manager"]->onPlayerInteractWithShovel($event->getPlayer(), $event->getBlock());
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
			$winnerText = implode(", ", $winner);

			foreach($winner as $winnerName){
				if(isset($this->wins[$winnerName])){
					$this->wins[$winnerName]++;
				}else{
					$this->wins[$winnerName] = 0;
				}
				//$winnerText .= "$winnerName, ";
			}

			arsort($this->wins);

			//$winnerText = substr($winnerText, 0, -2);

			$this->getServer()->broadcastMessage(TextFormat::AQUA.FreedomDive::getTranslation("FINISH_WINNER", $serverId, $winnerText));
			$wins = (new Config($this->getDataFolder()."wins.yml", Config::YAML));
			$wins->setAll($this->wins);
			$wins->save();
		}
	}

	/**
	 * @param string $playerName name of player
	 * @return WorldManager
	 */
	public function getWorldManagerByPlayerName($playerName){
		if(!isset($this->worlds[$this->players[$playerName]])) return null;

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

	public function sendMessage(){
		foreach($this->worlds as $worldData){
			$text = $worldData["manager"]->getTip();
			foreach($worldData["manager"]->player as $playerData){
				$playerData["player"]->sendPopup($text);
			}
		}
	}

	public function tick(){
		foreach($this->worlds as $worldData){
			$worldData["manager"]->onTick();
		}
	}

	public static function getTranslation($key, ...$params){
		if(!isset(self::$translations[$key])){
			return $key.", ".implode(", ", $params);
		}

		$translation = self::$translations[$key];

		foreach($params as $key => $value){
			$translation = str_replace("%s".($key + 1), $value, $translation);
		}

		return $translation;
	}

	public function setPlayerWorld($playerName, $worldFolderName){
		$this->players[$playerName] = $worldFolderName;
	}
}
