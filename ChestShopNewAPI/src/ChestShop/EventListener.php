<?php

namespace ChestShop;

use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\block\SignChangeEvent;
use pocketmine\event\Listener;
use pocketmine\block\Block;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\item\Item;
use pocketmine\level\Position;
use pocketmine\math\Vector3;
use pocketmine\tile\Chest as TileChest;
use pocketmine\utils\TextFormat;
use MassiveEconomy\MassiveEconomyAPI;

class EventListener implements Listener
{
	const SUPPORTED_TYPES = [Block::CHEST, Block::TRAPPED_CHEST];
	
	private $plugin;
	private $databaseManager;

	public function __construct(ChestShop $plugin, DatabaseManager $databaseManager)
	{
		$this->plugin = $plugin;
		$this->databaseManager = $databaseManager;
	}


//Get player by full name, fix selection of, say, SKYLADD when the owner is SKY (name short-typing mechanisms can be a pain in the arse)
	private function getPlayerByName($name){
		$player = $this->plugin->getServer()->getPlayer($name);
		if($player !== null and strtolower($player->getName()) == strtolower($name)){
			//Check: A) if the player is online, and B: if the player name matches the specified name exactly
			//This should fix short-type name bugs
			return $player;
		}else{
			return false;
		}
	}

	public function onBlockPlaced(BlockPlaceEvent $event){
		$block = $event->getBlock();
		foreach(self::SUPPORTED_TYPES as $type){
			if($block->getID() === $type){
				if(($chests = $this->getSideChest($block)) !== false){
					foreach($chests as $chest){
						$condition = [
							"chestX" => $chest->getX(),
							"chestY" => $chest->getY(),
							"chestZ" => $chest->getZ()
						];
						$shopInfo = $this->databaseManager->selectByCondition($condition);
						if($shopInfo !== false){
							$event->setCancelled();
							return;
						}
		
					}
				break;
				}
			}
		}
	}

//Sign tap

	public function onPlayerInteract(PlayerInteractEvent $event){
		// Ignore left-click events, fixes spam of Bought blah blah messages when destroying a shop
		if($event->getAction() == $event::LEFT_CLICK_BLOCK){
			$this->plugin->getServer()->getLogger()->debug("{$event->getPlayer()->getName()} left-clicked, ignoring");
			return;
		}
		
		$block = $event->getBlock();
		$player = $event->getPlayer();
		switch ($block->getID()) {
			case Block::SIGN_POST:
			case Block::WALL_SIGN:
				if (($shopInfo = $this->databaseManager->selectByCondition([
						"signX" => $block->getX(),
						"signY" => $block->getY(),
						"signZ" => $block->getZ()
					])) === false) return;
				$event->setCancelled();
				$buyerMoney = $this->plugin->getServer()->getPluginManager()->getPlugin("MassiveEconomy")->getMoney(strtolower($player->getName()));
				if (!is_numeric($buyerMoney)) { // Checks for simple errors
					$player->sendTip("§a[Shop]§r Couldn't acquire your money data!");
					return;
				}
				if ($buyerMoney < $shopInfo['price']) {
					$player->sendTip("§a[Shop]§r Not enough money");
					$this->plugin->getServer()->getLogger()->debug("{$event->getPlayer()->getName()} didn't have enough money to buy");
					return;
				}
				//@var TileChest $chest
				$chest = $player->getLevel()->getTile(new Vector3($shopInfo['chestX'], $shopInfo['chestY'], $shopInfo['chestZ']));
				$itemNum = 0;
				$pID = $shopInfo['productID'];
				$pMeta = $shopInfo['productMeta'];
				$productName = Item::fromString($pID.":".$pMeta)->getName();
				for ($i = 0; $i < $chest->getSize(); $i++) {
					$item = $chest->getInventory()->getItem($i);
					//Use getDamage() method to get metadata of item
					if ($item->getID() === $pID and $item->getDamage() === $pMeta) $itemNum += $item->getCount();
				}
				if ($shopInfo['shopOwner'] === strtolower($player->getName())) {
				$player->addWindow($chest->getInventory());
					return;
				}
				$price = $shopInfo['price'];
				$saleNum = $shopInfo['saleNum'];
				if($player->getGamemode() == 1){
					if ($saleNum < 1) {
					$this->plugin->getServer()->getPluginManager()->getPlugin("MassiveEconomy")->payMoneyToPlayer(strtolower($player->getName()), $price, $shopInfo['shopOwner']);
					$player->sendTip("§a[Shop]§r You donated {$price} to {$shopInfo["shopOwner"]}");
					return;
					}
					$player->sendTip("§a[Shop]§r You can't buy in creative");
					$event->setCancelled();
					$this->plugin->getServer()->getLogger()->debug("{$event->getPlayer()->getName()} tried buying in creative mode");
					return;
				}
				if ($itemNum < $saleNum) {
					// Need to check if the returned player's name is equal to the shop owner, fix short-type bugs
					if (($p = $this->getPlayerByName($shopInfo["shopOwner"])) !== false) {
						$p->sendTip("§a[Shop]§r Your $productName shop is out of stock");
					}
					if($itemNum == 0){
						$player->sendTip("§a[Shop]§r This shop is out of stock");
						$this->plugin->getServer()->getLogger()->debug("{$event->getPlayer()->getName()}'s shop is out of stock");
						return;
					}else{
						//Not enough stock to make a full sale, make partial sale instead
						$price = round((($price/$saleNum)*$itemNum),2);
						$saleNum = $itemNum;
					}
				}
				//TODO add selling to shops
				$player->getInventory()->addItem(clone Item::get((int)$shopInfo['productID'], (int)$shopInfo['productMeta'], (int)$saleNum));

				$tmpNum = $saleNum;
				for ($i = 0; $i < $chest->getSize(); $i++) {
					$item = $chest->getInventory()->getItem($i);
					//Use getDamage() method to get metadata of item
					if ($item->getID() === $pID and $item->getDamage() === $pMeta) {
						if ($item->getCount() <= $tmpNum) {
							$chest->getInventory()->setItem($i, Item::get(Item::AIR, 0, 0));
							$tmpNum -= $item->getCount();
						} else {
							$chest->getInventory()->setItem($i, Item::get($item->getID(), $pMeta, $item->getCount() - $tmpNum));
							break;
						}
					}
				}
				$this->plugin->getServer()->getPluginManager()->getPlugin("MassiveEconomy")->payMoneyToPlayer(strtolower($player->getName()), $price, $shopInfo['shopOwner']);
				if ($saleNum > 0){
					$player->sendTip("§a[Shop]§r You bought {$saleNum} $productName from {$shopInfo["shopOwner"]} for {$price}" . MassiveEconomyAPI::getInstance()->getMoneySymbol());
					if (($p = $this->getPlayerByName($shopInfo["shopOwner"])) !== false) {
						$p->sendTip("§a[Shop]§r {$player->getName()} bought {$saleNum} $productName from you for {$price}" . MassiveEconomyAPI::getInstance()->getMoneySymbol());
						$this->plugin->getServer()->getLogger()->debug("{$event->getPlayer()->getName()} bought from {$shopInfo["shopOwner"]}");
					}
				}else{
					$player->sendTip("§a[Shop]§r Donated {$price}" . MassiveEconomyAPI::getInstance()->getMoneySymbol());
					if (($p = $this->getPlayerByName($shopInfo["shopOwner"])) !== false) {
						$p->sendTip("§a[Shop]§r {$player->getName()} donated {$price}" . MassiveEconomyAPI::getInstance()->getMoneySymbol());
					}
				}
				break;

//Chest tap

			case Block::CHEST:
			case Block::TRAPPED_CHEST:
				$shopInfo = $this->databaseManager->selectByCondition([
					"chestX" => $block->getX(),
					"chestY" => $block->getY(),
					"chestZ" => $block->getZ()
				]);
				if($shopInfo !== false){
					if($player->hasPermission('chestshop.manager')){
						$this->plugin->getServer()->getLogger()->debug("{$event->getPlayer()->getName()} opened {$shopInfo['shopOwner']}'s shop ");
						return;
					}
					if ($shopInfo['shopOwner'] !== strtolower($player->getName())) {
						$event->setCancelled();
						return;
					}
					if($player->hasPermission('chestshop.creative')){
						return;
					}
					if($player->getGamemode() == 1){
						$player->sendTip("§a[Shop]§r You can't stock in creative");
						$event->setCancelled();
						$this->plugin->getServer()->getLogger()->debug("{$event->getPlayer()->getName()} tried stocking in creative");
						return;
					}
				}
				break;

			default:
				break;
		}
	}

//Shop protection

	private function destroyByCondition(&$event, $condition){

		$player = $event->getPlayer();
		$shopInfo = $this->databaseManager->selectByCondition($condition);
		if ($shopInfo !== false) {
			
			if ($shopInfo['shopOwner'] !== strtolower($player->getName()) and !$player->hasPermission("chestshop.manager")){
				$this->plugin->getServer()->getLogger()->debug("{$event->getPlayer()->getName()} tried to break {$shopInfo['shopOwner']}'s shop");
				$event->setCancelled();
				return;
			}
			
			// This statement is only reachable if the player either owns the shop or has permission to destroy any shop.
			$this->databaseManager->deleteByCondition($condition);
			$this->plugin->getServer()->getLogger()->debug("{$event->getPlayer()->getName()} removed {$shopInfo['shopOwner']}'s shop");
			return;
		}
				$this->plugin->getServer()->getLogger()->debug("destroyByCondition method ended");
	}
  
//Break shop protection

	public function onPlayerBreakBlock(BlockBreakEvent $event){

		$block = $event->getBlock();
		$condition = [];
		switch ($block->getID()) {
			case Block::SIGN_POST:
			case Block::WALL_SIGN:
				$condition = [
					"signX" => $block->getX(),
					"signY" => $block->getY(),
					"signZ" => $block->getZ()
				];
				break;
			case Block::CHEST:
			case Block::TRAPPED_CHEST:	
				$condition = [
					"chestX" => $block->getX(),
					"chestY" => $block->getY(),
					"chestZ" => $block->getZ()
				];
				break;
			default:
					$this->plugin->getServer()->getLogger()->debug("BlockBreakEvent ended");
				return;
		}
		// This statement will only be reachable if the block is a potential Shop block
		// This method will then decide if the block is a shop block or not, and handle permissions and ownership
		$this->destroyByCondition($event, $condition);
		$this->plugin->getServer()->getLogger()->debug("Handling chest/sign destroy event ended");
	}
                  
//Sign register to shop

	public function onSignChange(SignChangeEvent $event){
		$sign = $event->getBlock();
		$condition = [
			"signX" => $sign->getX(),
			"signY" => $sign->getY(),
			"signZ" => $sign->getZ()
		];
		$shopInfo = $this->databaseManager->selectByCondition($condition);
		if ($shopInfo !== false){
			// Anti-spam mechanism triggered, cancel event for a shop that is already registered
			// This fixes the occasional bug where the sign text resets to what the player typed onto it when it was made
			$this->plugin->getServer()->getLogger()->debug("Anti-spam mechanism triggered, cancelling event to prevent reverting sign text");				
			$event->setCancelled();
			return;
		}
		
		$shopOwner = strtolower($event->getPlayer()->getName());
		$price = $event->getLine(2);
		$saleNum = $event->getLine(1);
		$item = Item::fromString($event->getLine(3));
		if($item->getID() < 1){ //Invalid item ID/name
			return;
		}


//Sign format

		$pID = $item->getID();
		$pMeta = $item->getDamage();

		if ($event->getLine(0) !== "") return;
		if ($price == 0 and $saleNum == 0){
		return;
		}
		if (!ctype_digit($saleNum)) return;
		if (!is_numeric($price) or $price < 0) return;
		if ($pID === false) return;
		if (($chest = $this->getSideChest($sign)) === false) return;
		// Set sign format
		$productName = $item->getName();
		$event->setLine(0, TextFormat::WHITE.$event->getPlayer()->getName());
		$event->setLine(1, ($saleNum == 0? "DONATE" : "$saleNum"));
		$event->setLine(2, ($price == 0? "FREE" : "B ".round($price,2) . MassiveEconomyAPI::getInstance()->getMoneySymbol()));
		$event->setLine(3, "$productName");
		
		// Quick hack to fix this for now, it will need more work in the near future
		$this->databaseManager->registerShop($shopOwner, $saleNum, round($price,2), $pID, $pMeta, $sign, $chest[0]);
		
		// Check for double chest
		if(($pairChest = $event->getBlock()->getLevel()->getTile($chest[0])->getPair()) !== null){
			$this->plugin->getServer()->getLogger()->debug("{$event->getPlayer()->getName()} created a double chest shop");
			$this->databaseManager->registerShop($shopOwner, $saleNum, round($price,2), $pID, $pMeta, $sign, $pairChest);
		}
		$this->plugin->getServer()->getLogger()->debug("{$event->getPlayer()->getName()} made a shop");
		return;
	}

// Where sign can be placed for usable shop
// This has potentially serious issues though, because what if you place a sign between 2 chests? Which one does it pick?
// Possibly not the one the player intends, this will need refinement.

//Return an array of chests found
	private function getSideChest(Position $pos){
		$found = [];
		
		foreach(self::SUPPORTED_TYPES as $type){
			if(($block = $pos->getLevel()->getBlock(new Vector3($pos->getX() - 1, $pos->getY(), $pos->getZ())))->getID() === $type) $found[] = $block;
			if(($block = $pos->getLevel()->getBlock(new Vector3($pos->getX() + 1, $pos->getY(), $pos->getZ())))->getID() === $type) $found[] = $block;			
			
			if(($block = $pos->getLevel()->getBlock(new Vector3($pos->getX(), $pos->getY() - 1, $pos->getZ())))->getID() === $type) $found[] = $block;
			if(($block = $pos->getLevel()->getBlock(new Vector3($pos->getX(), $pos->getY() + 1, $pos->getZ())))->getID() === $type) $found[] = $block;
			
			if(($block = $pos->getLevel()->getBlock(new Vector3($pos->getX(), $pos->getY(), $pos->getZ() - 1)))->getID() === $type) $found[] = $block;
			if(($block = $pos->getLevel()->getBlock(new Vector3($pos->getX(), $pos->getY(), $pos->getZ() + 1)))->getID() === $type) $found[] = $block;
		}
		
		if(count($found) === 0){
			return false;
		}else{
			return $found;
		}
	}
}
