<?php

namespace pocketmine\inventory;

use pocketmine\entity\Human;
use pocketmine\event\entity\EntityArmorChangeEvent;
use pocketmine\event\entity\EntityInventoryChangeEvent;
use pocketmine\event\player\PlayerItemHeldEvent;
use pocketmine\item\Item;
use pocketmine\network\protocol\ContainerOpenPacket;
use pocketmine\network\protocol\MobArmorEquipmentPacket;
use pocketmine\network\protocol\MobEquipmentPacket;
use pocketmine\Player;
use pocketmine\Server;
use pocketmine\network\protocol\v120\InventoryContentPacket;
use pocketmine\network\protocol\v120\Protocol120;
use pocketmine\network\protocol\v120\InventorySlotPacket;

class PlayerInventory extends BaseInventory{
	
	const OFFHAND_ARMOR_SLOT_ID = 4;
	const CURSOR_INDEX = -1;
	const CREATIVE_INDEX = -2;
	const CRAFT_INDEX_0 = -3;
	const CRAFT_INDEX_1 = -4;
	const CRAFT_INDEX_2 = -5;
	const CRAFT_INDEX_3 = -6;
	const CRAFT_INDEX_4 = -7;
	const CRAFT_INDEX_5 = -8;
	const CRAFT_INDEX_6 = -9;
	const CRAFT_INDEX_7 = -10;
	const CRAFT_INDEX_8 = -11;
	const CRAFT_RESULT_INDEX = -12;
	const QUICK_CRAFT_INDEX_OFFSET = -100;

	protected $itemInHandIndex = 0;
	/** @var Item */
	protected $cursor = null;
	/** @var Item[] */
	protected $craftSlots = [];
	/** @var Item */
	protected $craftResult = null;
	/** @var Item[] */
	protected $quickCraftSlots = []; // reason: bug with quick craft
	/** @var boolean */
	protected $isQuickCraftEnabled = false;

	public function __construct(Human $player) {
		parent::__construct($player, InventoryType::get(InventoryType::PLAYER));
		$this->cursor = Item::get(Item::AIR);
		for ($i = 0; $i < 9; $i++) {
			$this->craftSlots[$i] = clone $this->cursor;
		}
	}

	public function __toString() {
		$result = "";
		foreach ($this->getContents() as $index => $item) {
			$result .= $index . " - " . $item . PHP_EOL;
		}
		return $result;
	}

	public function getSize() {
		return parent::getSize() - 5; //Remove armor slots
	}

	public function setSize($size) {
		parent::setSize($size + 5);
	}
	
	/**
	 * @deprecated
	 * 
	 * @param int $index
	 * @return Item
	 */
	public function getHotbatSlotItem($index) {
		return $this->getItem($index);
	}

	public function getHotbarSlotIndex($index) {
		return ($index >= 0 && $index < $this->getHotbarSize()) ? $index : -1;
	}

	public function setHotbarSlotIndex($index, $slot) {
		if ($index == $slot || $slot < 0) {
			return;
		}
		$tmp = $this->getItem($index);
		$this->setItem($index, $this->getItem($slot));
		$this->setItem($slot, $tmp);
	}

	public function getHeldItemIndex() {
		return $this->itemInHandIndex;
	}

	public function setHeldItemIndex($index, $isNeedSendToHolder = true) {
		if ($index >= 0 and $index < $this->getHotbarSize()) {
			$this->itemInHandIndex = $index;
			if ($isNeedSendToHolder === true && $this->getHolder() instanceof Player) {
				$this->sendHeldItem($this->getHolder());
			}
			$this->sendHeldItem($this->getHolder()->getViewers());
		}
	}

	public function getItemInHand() {
		return $this->getItem($this->getHeldItemSlot());
	}

	/**
	 * @param Item $item
	 *
	 * @return bool
	 */
	public function setItemInHand(Item $item) {
		return $this->setItem($this->getHeldItemSlot(), $item);
	}

	/**
	 * @deprecated No longer used by internal code and not recommended.
	 */
	public function getHeldItemSlot() {
		return $this->itemInHandIndex;
	}

	/**
	 * @deprecated No longer used by internal code and not recommended.
	 */
	public function setHeldItemSlot($slot) {
		if ($slot >= -1 && $slot < $this->getSize()) {
			$item = $this->getItem($slot);

			$itemIndex = $this->getHeldItemIndex();

			if($this->getHolder() instanceof Player){
				Server::getInstance()->getPluginManager()->callEvent($ev = new PlayerItemHeldEvent($this->getHolder(), $item, $slot, $itemIndex));
				if($ev->isCancelled()){
					$this->sendContents($this->getHolder());
					return;
				}
			}

			$this->setHotbarSlotIndex($itemIndex, $slot);
		}
	}

	/**
	 * @param Player|Player[] $target
	 */
	public function sendHeldItem($target){
		$item = $this->getItemInHand();

		$pk = new MobEquipmentPacket();
		$pk->eid = $this->getHolder()->getId();
		$pk->item = $item;
		$pk->slot = $this->getHeldItemSlot();
		$pk->selectedSlot = $this->getHeldItemIndex();

		$level = $this->getHolder()->getLevel();
		if(!is_array($target)){
			if($level->mayAddPlayerHandItem($this->getHolder(), $target)) {
				$target->dataPacket($pk);
				if($target === $this->getHolder()){
					$this->sendSlot($this->getHeldItemSlot(), $target);
				}
			}
		}else{
			foreach($target as $player){
				if($level->mayAddPlayerHandItem($this->getHolder(), $player)) {
					$player->dataPacket($pk);
					if($player === $this->getHolder()){
						$this->sendSlot($this->getHeldItemSlot(), $player);
					}
				}
			}
		}
	}

	public function onSlotChange($index, $before, $sendPacket = true){
		$holder = $this->getHolder();
		if ($holder instanceof Player and !$holder->spawned) {
			return;
		}

		parent::onSlotChange($index, $before, $sendPacket);

		if ($index >= $this->getSize() && $sendPacket === true) {
			$this->sendArmorSlot($index, $this->getHolder()->getViewers());
		}
	}

	public function getHotbarSize(){
		return 9;
	}

	public function getArmorItem($index){
		return $this->getItem($this->getSize() + $index);
	}

	public function setArmorItem($index, Item $item, $sendPacket = true){
		return $this->setItem($this->getSize() + $index, $item, $sendPacket);
	}

	public function getHelmet(){
		return $this->getItem($this->getSize());
	}

	public function getChestplate(){
		return $this->getItem($this->getSize() + 1);
	}

	public function getLeggings(){
		return $this->getItem($this->getSize() + 2);
	}

	public function getBoots(){
		return $this->getItem($this->getSize() + 3);
	}

	public function setHelmet(Item $helmet){
		return $this->setItem($this->getSize(), $helmet);
	}

	public function setChestplate(Item $chestplate){
		return $this->setItem($this->getSize() + 1, $chestplate);
	}

	public function setLeggings(Item $leggings){
		return $this->setItem($this->getSize() + 2, $leggings);
	}

	public function setBoots(Item $boots){
		return $this->setItem($this->getSize() + 3, $boots);
	}

	public function setItem($index, Item $item, $sendPacket = true) {
		if ($index >= 0) { // base inventory logic
			if($item->getId() === Item::AIR || $item->getCount() <= 0){
				return $this->clear($index);
			}
			if ($index >= $this->getSize()) { // Armor change
				Server::getInstance()->getPluginManager()->callEvent($ev = new EntityArmorChangeEvent($this->getHolder(), $this->getItem($index), $item, $index));
				if ($ev->isCancelled() && $this->getHolder() instanceof Human) {
					$this->sendArmorSlot($index, $this->getHolder());
					return false;
				}
				$item = $ev->getNewItem();
			} else {
				Server::getInstance()->getPluginManager()->callEvent($ev = new EntityInventoryChangeEvent($this->getHolder(), $this->getItem($index), $item, $index));
				if ($ev->isCancelled()) {
					$this->sendSlot($index, $this->getHolder());
					return false;
				}
				$index = $ev->getSlot();
				$item = $ev->getNewItem();
			}
			$old = $this->getItem($index);
			$this->slots[$index] = clone $item;
			$this->onSlotChange($index, $old, $sendPacket);
			return true;
		}
		switch ($index) {
			case self::CURSOR_INDEX:
				$this->cursor = clone $item;
				if ($sendPacket) {
					$this->sendCursor();
				}
				break;
			case self::CRAFT_INDEX_0:
			case self::CRAFT_INDEX_1:
			case self::CRAFT_INDEX_2:
			case self::CRAFT_INDEX_3:
			case self::CRAFT_INDEX_4:
			case self::CRAFT_INDEX_5:
			case self::CRAFT_INDEX_6:
			case self::CRAFT_INDEX_7:
			case self::CRAFT_INDEX_8:
				$slot = self::CRAFT_INDEX_0 - $index;
				$this->craftSlots[$slot] = clone $item;
				break;
			case self::CRAFT_RESULT_INDEX:
				$this->craftResult = clone $item;
				break;
			default:
				if ($index <= self::QUICK_CRAFT_INDEX_OFFSET) {
					$slot = self::QUICK_CRAFT_INDEX_OFFSET - $index;
					$this->quickCraftSlots[$slot] = clone $item;
				}
				break;
		}
		return true;
	}

	public function getItem($index) {
		if ($index < 0) {
			switch ($index) {
				case self::CURSOR_INDEX:
					return $this->cursor == null ? clone $this->air : clone $this->cursor;
				case self::CRAFT_INDEX_0:
				case self::CRAFT_INDEX_1:
				case self::CRAFT_INDEX_2:
				case self::CRAFT_INDEX_3:
				case self::CRAFT_INDEX_4:
				case self::CRAFT_INDEX_5:
				case self::CRAFT_INDEX_6:
				case self::CRAFT_INDEX_7:
				case self::CRAFT_INDEX_8:
					$slot = self::CRAFT_INDEX_0 - $index;
					return $this->craftSlots[$slot] == null ? clone $this->air : clone $this->craftSlots[$slot];
				case self::CRAFT_RESULT_INDEX:
					return $this->craftResult == null ? clone $this->air : clone $this->craftResult;
				default:
					if ($index <= self::QUICK_CRAFT_INDEX_OFFSET) {
						$slot = self::QUICK_CRAFT_INDEX_OFFSET - $index;
						return !isset($this->quickCraftSlots[$slot]) || $this->quickCraftSlots[$slot] == null ? clone $this->air : clone $this->quickCraftSlots[$slot];
					}
					break;
			}
			return clone $this->air;
		} else {
			return parent::getItem($index);
		}
	}

	/**
	 * 
	 * @param integer $slotIndex
	 * @return boolean
	 */
	public function clear($slotIndex) {
		if (isset($this->slots[$slotIndex])) {
			if ($this->isArmorSlot($slotIndex)) { //Armor change
				$ev = new EntityArmorChangeEvent($this->holder, $this->slots[$slotIndex], clone $this->air, $slotIndex);
				Server::getInstance()->getPluginManager()->callEvent($ev);
				if ($ev->isCancelled()) {
					$this->sendArmorSlot($slotIndex, $this->holder);
					return false;
				}
			} else {
				$ev = new EntityInventoryChangeEvent($this->holder, $this->slots[$slotIndex], clone $this->air, $slotIndex);
				Server::getInstance()->getPluginManager()->callEvent($ev);
				if ($ev->isCancelled()) {
					$this->sendSlot($slotIndex, $this->holder);
					return false;
				}
			}
			$oldItem = $this->slots[$slotIndex];
			$newItem = $ev->getNewItem();
			if ($newItem->getId() !== Item::AIR) {
				$this->slots[$slotIndex] = clone $newItem;
			} else {
				unset($this->slots[$slotIndex]);
			}
			$this->onSlotChange($slotIndex, $oldItem);
		}
		return true;
	}

	/**
	 * @return Item[]
	 */
	public function getArmorContents(){
		$armor = [];

		for($i = 0; $i < 4; ++$i){
			$armor[$i] = $this->getItem($this->getSize() + $i);
		}

		return $armor;
	}

	public function clearAll(){
		$limit = $this->getSize() + 5;
		for($index = 0; $index < $limit; ++$index){
			$this->clear($index);
		}
		for ($index = self::CRAFT_INDEX_0; $index >= self::CRAFT_INDEX_8; $index--) {
			$this->setItem($index, clone $this->air);
		}
		$this->cursor = null;
	}

	/**
	 * @param Player|Player[] $target
	 */
	public function sendArmorContents($target) {
		if ($target instanceof Player) {
			$target = [$target];
		}
		$armor = $this->getArmorContents();

		$pk = new MobArmorEquipmentPacket();
		$pk->eid = $this->holder->getId();
		$pk->slots = $armor;

		foreach ($target as $player) {
			if ($player === $this->holder) {
				$pk2 = new InventoryContentPacket();
				$pk2->inventoryID = Protocol120::CONTAINER_ID_ARMOR;
				$pk2->items = $armor;
				$player->dataPacket($pk2);
			} else {
				$player->dataPacket($pk);
			}
		}
		$this->sendOffHandContents($target);
	}
	
	/**
	 * 
	 * @param Player[] $target
	 */
	protected function sendOffHandContents($targets) {
		$pk = new MobEquipmentPacket();
		$pk->eid = $this->getHolder()->getId();
		$pk->item = $this->getItem($this->getSize() + self::OFFHAND_ARMOR_SLOT_ID);
		$pk->slot = $this->getHeldItemIndex();
		$pk->selectedSlot = $pk->slot;
		$pk->windowId = MobEquipmentPacket::WINDOW_ID_PLAYER_OFFHAND;
		foreach ($targets as $player) {
			if ($player === $this->getHolder()) {
				$pk2 = new InventoryContentPacket();
				$pk2->inventoryID = Protocol120::CONTAINER_ID_OFFHAND;
				$pk2->items = [ $this->getArmorItem(self::OFFHAND_ARMOR_SLOT_ID) ];
				$player->dataPacket($pk2);
			} else {
				$player->dataPacket($pk);
			}
		}
	}

	/**
	 * @param Item[] $items
	 */
	public function setArmorContents(array $items, $sendPacket = true){
		for($i = 0; $i < 4; ++$i){
			if(!isset($items[$i]) or !($items[$i] instanceof Item)){
				$items[$i] = clone $this->air;
			}

			if($items[$i]->getId() === Item::AIR){
				$this->clear($this->getSize() + $i);
			}else{
				$this->setItem($this->getSize() + $i, $items[$i], $sendPacket);
			}
		}
	}


	/**
	 * 
	 * @param integer $index
	 * @param Player[] $target
	 */
	public function sendArmorSlot($index, $target){
		if ($target instanceof Player) {
			$target = [$target];
		}
		if ($index - $this->getSize() == self::OFFHAND_ARMOR_SLOT_ID) {
			$this->sendOffHandContents($target);
		} else {
			$pk = new MobArmorEquipmentPacket();
			$pk->eid = $this->holder->getId();
			$pk->slots = $this->getArmorContents();
			foreach($target as $player){
				if ($player === $this->holder) {
					/** @var Player $player */
					$pk2 = new InventorySlotPacket();
					$pk2->containerId = Protocol120::CONTAINER_ID_ARMOR;
					$pk2->slot = $index - $this->getSize();
					$pk2->item = $this->getItem($index);
					$player->dataPacket($pk2);
				} else {
					$player->dataPacket($pk);
				}
			}
		}
	}

	public function sendContents($target) {
		$pk = new InventoryContentPacket();
		$pk->inventoryID = Protocol120::CONTAINER_ID_INVENTORY;
		$pk->items = [];

		$mainPartSize = $this->getSize();
		for ($i = 0; $i < $mainPartSize; $i++) { // Do not send armor by error here
			$pk->items[$i] = $this->getItem($i);
		}

		$this->holder->dataPacket($pk);
		$this->sendCursor();
	}

	public function sendCursor() {
		$pk = new InventorySlotPacket();
		$pk->containerId = Protocol120::CONTAINER_ID_CURSOR_SELECTED;
		$pk->slot = 0;
		$pk->item = $this->cursor;
		$this->holder->dataPacket($pk);
	}

	/**
	 * @param integer $index
	 * @param Player $target
	 */
	public function sendSlot($index, $target) {
		$pk = new InventorySlotPacket();
		$pk->containerId = Protocol120::CONTAINER_ID_INVENTORY;
		$pk->slot = $index;
		$pk->item = $this->getItem($index);
		$this->holder->dataPacket($pk);
	}

	/**
	 * @return Human|Player
	 */
	public function getHolder() {
		return parent::getHolder();
	}
	
	public function removeItemWithCheckOffHand($searchItem) {
		$offhandSlotId = $this->getSize() + self::OFFHAND_ARMOR_SLOT_ID;
		$item = $this->getItem($offhandSlotId);
		if ($item->getId() !== Item::AIR && $item->getCount() > 0) {
			if ($searchItem->equals($item, $searchItem->getDamage() === null ? false : true, $searchItem->getId() == Item::ARROW || $searchItem->getCompound() === null ? false : true)) {
				$amount = min($item->getCount(), $searchItem->getCount());
				$searchItem->setCount($searchItem->getCount() - $amount);
				$item->setCount($item->getCount() - $amount);
				$this->setItem($offhandSlotId, $item);
				return;
			}
		}
		parent::removeItem($searchItem);
	}
	
	public function openSelfInventory() {
		$pk = new ContainerOpenPacket();
		$pk->windowid = Protocol120::CONTAINER_ID_INVENTORY;
		$pk->type = -1;
		$pk->slots = $this->getSize();
		$pk->x = $this->getHolder()->getX();
		$pk->y = $this->getHolder()->getY();
		$pk->z = $this->getHolder()->getZ();
		$this->getHolder()->dataPacket($pk);
	}
	
	public function forceSetSlot($index, Item $item) {
		$this->slots[$index] = clone $item;
	}

	/**
	 * 
	 * @return Item[]
	 */
	public function getCraftContents() {
		return $this->craftSlots;
	}
	
	/**
	 * 
	 * @param integer $slotIndex
	 * @return boolean
	 */
	protected function isArmorSlot($slotIndex) {
		return $slotIndex >= $this->getSize();
	}
	
	public function close(Player $who) {
		parent::close($who);
		$isChanged = false;
		foreach ($this->craftSlots as $index => $slot) {
			if ($slot->getId() != Item::AIR) {
				$this->addItem($slot);
				$this->craftSlots[$index] = Item::get(Item::AIR, 0, 0);
				$isChanged = true;
			}
		}
		if ($isChanged) {
			$this->sendContents($this->holder);
		}
	}
	
	public function setQuickCraftMode($value) {
		$this->isQuickCraftEnabled = $value;
		$this->quickCraftSlots = [];
	}
	
	public function isQuickCraftEnabled() {
		return $this->isQuickCraftEnabled;
	}
	
	public function getNextFreeQuickCraftSlot() {
		return self::QUICK_CRAFT_INDEX_OFFSET - count($this->quickCraftSlots);
	}
	
	public function getQuckCraftContents() {
		return $this->quickCraftSlots;
	}

}