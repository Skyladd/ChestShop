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


class EventListener implements Listener
{
    private $plugin;
    private $databaseManager;

    public function __construct(ChestShop $plugin, DatabaseManager $databaseManager)
    {
        $this->plugin = $plugin;
        $this->databaseManager = $databaseManager;
    }

    public function onPlayerInteract(PlayerInteractEvent $event)
    {
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
                if ($shopInfo['shopOwner'] === $player->getName()) {
                    $player->sendMessage(TextFormat::RED."Cannot buy from your own shop");
                    return;
                }
                $buyerMoney = $this->plugin->getServer()->getPluginManager()->getPlugin("MassiveEconomy")->getMoney($player->getName());
                if (!is_numeric($buyerMoney)) { // Probably $buyerMoney is instance of SimpleError
                    $player->sendMessage("Couldn't acquire your money data!");
                    return;
                }
                if ($buyerMoney < $shopInfo['price']) {
                    $player->sendMessage(TextFormat::RED."Not enough money");
                    return;
                }
                /** @var TileChest $chest */
                $chest = $player->getLevel()->getTile(new Vector3($shopInfo['chestX'], $shopInfo['chestY'], $shopInfo['chestZ']));
                $itemNum = 0;
                $pID = $shopInfo['productID'];
                $pMeta = $shopInfo['productMeta'];
				$productName = Item::fromString($pID.":".$pMeta)->getName();
                for ($i = 0; $i < $chest->getSize(); $i++) {
                    $item = $chest->getInventory()->getItem($i);
                    // use getDamage() method to get metadata of item
                    if ($item->getID() === $pID and $item->getDamage() === $pMeta) $itemNum += $item->getCount();
                }
                if ($itemNum < $shopInfo['saleNum']) {
                    $player->sendMessage(TextFormat::RED."This shop is out of stock");
                    if (($p = $this->plugin->getServer()->getPlayer($shopInfo['shopOwner'])) !== null) {
                        $p->sendMessage(TextFormat::RED."Your $productName shop is out of stock");
                    }
                    return;
                }

                //TODO Improve this
                $player->getInventory()->addItem(clone Item::get((int)$shopInfo['productID'], (int)$shopInfo['productMeta'], (int)$shopInfo['saleNum']));

                $tmpNum = $shopInfo['saleNum'];
                for ($i = 0; $i < $chest->getSize(); $i++) {
                    $item = $chest->getInventory()->getItem($i);
                    // Use getDamage() method to get metadata of item
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
                $this->plugin->getServer()->getPluginManager()->getPlugin("MassiveEconomy")->payMoneyToPlayer($player->getName(), $shopInfo['price'], $shopInfo['shopOwner']);

                $player->sendMessage(TextFormat::GREEN."Bought {$shopInfo['saleNum']} $productName for {$shopInfo['price']}$");
                if (($p = $this->plugin->getServer()->getPlayer($shopInfo['shopOwner'])) !== null) {
                    $p->sendMessage(TextFormat::WHITE."{$player->getName()} bought {$shopInfo['saleNum']} $productName for {$shopInfo['price']}$");
                }
                break;

            case Block::CHEST:
                $shopInfo = $this->databaseManager->selectByCondition([
                    "chestX" => $block->getX(),
                    "chestY" => $block->getY(),
                    "chestZ" => $block->getZ()
                ]);
				if($shopInfo !== false){
					if($player->getGamemode() == 1){
						$player->sendMessage(TextFormat::RED."You're not allowed to do that");
                        $event->setCancelled();
					}
					if ($shopInfo['shopOwner'] !== $player->getName()) {
						$player->sendMessage(TextFormat::RED."This isn't your shop");
						$event->setCancelled();
					}
				}
                break;

            default:
                break;
        }
    }

    public function onPlayerBreakBlock(BlockBreakEvent $event)
    {
        $block = $event->getBlock();
        $player = $event->getPlayer();

        switch ($block->getID()) {
            case Block::SIGN_POST:
            case Block::WALL_SIGN:
                $condition = [
                    "signX" => $block->getX(),
                    "signY" => $block->getY(),
                    "signZ" => $block->getZ()
                ];
                $shopInfo = $this->databaseManager->selectByCondition($condition);
                if ($shopInfo !== false) {
                    if ($shopInfo['shopOwner'] !== $player->getName()) {
                        $player->sendMessage(TextFormat::RED."This isn't your shop");
                        $event->setCancelled();
                    } else {
                        $this->databaseManager->deleteByCondition($condition);
                        $player->sendMessage(TextFormat::RED."Shop Closed");
                    }
                }
                break;

            case Block::CHEST:
                $condition = [
                    "chestX" => $block->getX(),
                    "chestY" => $block->getY(),
                    "chestZ" => $block->getZ()
                ];
                $shopInfo = $this->databaseManager->selectByCondition($condition);
                if ($shopInfo !== false) {
					
                    if ($shopInfo['shopOwner'] !== $player->getName()) {
                        $player->sendMessage(TextFormat::RED."This isn't your shop");
                        $event->setCancelled();
                    } else {
                        $this->databaseManager->deleteByCondition($condition);
                        $player->sendMessage(TextFormat::RED."Shop Closed");
                    }
                }
                break;

            default:
                break;
        }
    }

    public function onSignChange(SignChangeEvent $event)
    {
		if (!(($chest = $this->getSideChest($sign = $event->getBlock())) and strtolower($event->getLine(0)) == "")) return;
		
		// Anti message spam. Not sure why signChangeEvent sometimes gets triggered twice, but here's a fix
		$condition = [
                    "signX" => $sign->getX(),
                    "signY" => $sign->getY(),
                    "signZ" => $sign->getZ()
        ];
        $shopInfo = $this->databaseManager->selectByCondition($condition);
		if($shopInfo != false) return;

		
        $shopOwner = $event->getPlayer()->getName();
		$saleNum = $event->getLine(1);
        $price = $event->getLine(2);
        //$productData = explode(":", $event->getLine(3));
		$item = Item::fromString($event->getLine(3));
		if($item->getID() < 1){ //Invalid item ID/name
			//$event->getPlayer()->sendMessage(TextFormat::RED."Invalid item name or ID");
			//$event->setCancelled();
			return;
		}
        $pID = $item->getID();
        $pMeta = $item->getDamage();

        //$sign = $event->getBlock();

        // Check sign format...
        //if ($event->getLine(0) !== "") return;
        if (!is_numeric($saleNum) or $saleNum <= 0) return;
        if (!is_numeric($price) or $price < 0) return;
        if ($pID === false) return;
        //if (($chest = $this->getSideChest($sign)) === false) return;

        $productName = $item->getName();

        $event->setLine(0, TextFormat::WHITE.$shopOwner);
        $event->setLine(1, "B $saleNum");
        $event->setLine(2, ($price == 0? "FREE!": $price));
        $event->setLine(3, "$productName");

        $this->databaseManager->registerShop($shopOwner, $saleNum, $price, $pID, $pMeta, $sign, $chest);
		$event->getPlayer()->sendMessage(TextFormat::GREEN."Shop created successfully");
    }

    private function getSideChest(Position $pos){		
        $block = $pos->getLevel()->getBlock(new Vector3($pos->getX(), $pos->getY() - 1, $pos->getZ()));
        if ($block->getID() === Block::CHEST) return $block;
        $block = $pos->getLevel()->getBlock(new Vector3($pos->getX(), $pos->getY() + 1, $pos->getZ()));
        if ($block->getID() === Block::CHEST) return $block;
        $block = $pos->getLevel()->getBlock(new Vector3($pos->getX() + 1, $pos->getY(), $pos->getZ()));
        if ($block->getID() === Block::CHEST) return $block;
        $block = $pos->getLevel()->getBlock(new Vector3($pos->getX() - 1, $pos->getY(), $pos->getZ()));
        if ($block->getID() === Block::CHEST) return $block;
        $block = $pos->getLevel()->getBlock(new Vector3($pos->getX(), $pos->getY(), $pos->getZ() + 1));
        if ($block->getID() === Block::CHEST) return $block;
        $block = $pos->getLevel()->getBlock(new Vector3($pos->getX(), $pos->getY(), $pos->getZ() - 1));
        if ($block->getID() === Block::CHEST) return $block;
        return false;
    }

    private function isItem($id)
    {
        if (isset(Item::$list[$id])) return true;
        if (isset(Block::$list[$id])) return true;
        return false;
    }
} 
