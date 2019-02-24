<?php

/**
 * Copyright 2018 GamakCZ
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

declare(strict_types=1);

namespace skywars\arena;

use pocketmine\level\Level;
use pocketmine\level\Position;
use pocketmine\level\sound\AnvilUseSound;
use pocketmine\level\sound\ClickSound;
use pocketmine\scheduler\Task;
use pocketmine\tile\Sign;
use skywars\math\Time;
use skywars\math\Vector3;
use pocketmine\entity\Effect;
use pocketmine\entity\EffectInstance;

/**
 * Class ArenaScheduler
 * @package skywars\arena
 */
class ArenaScheduler extends Task {

    /** @var Arena $plugin */
    protected $plugin;

    /** @var int $startTime */
    public $startTime = 30;

    /** @var float|int $gameTime */
    public $gameTime = 60 * 3;

    /** @var int $restartTime */
    public $restartTime = 10;

    /** @var array $restartData */
    public $restartData = [];

    /**
     * ArenaScheduler constructor.
     * @param Arena $plugin
     */
    public function __construct(Arena $plugin) {
        $this->plugin = $plugin;
        //$this->gameTime = $plugin->data["game_time"];
        //$this->restartTime = $plugin->data["restart_time"];
        //$this->startTime = $plugin->data["wait_time"];
    }

    /**
     * @param int $currentTick
     */
    public function onRun(int $currentTick) {
        $this->reloadSign();

        if($this->plugin->setup) return;

        switch ($this->plugin->phase) {
            case Arena::PHASE_LOBBY:
                if(count($this->plugin->players) >= 2) {
                    $this->plugin->broadcastMessage("§a> Начало через " . Time::calculateTime($this->startTime) . " секунд.", Arena::MSG_TIP);
                    $this->startTime--;
                    if($this->startTime == 0) {
                        $this->plugin->startGame();
                        foreach ($this->plugin->players as $player) {
                            $this->plugin->level->addSound(new AnvilUseSound($player->asVector3()));
                        }
                    }
                    else {
                        foreach ($this->plugin->players as $player) {
                            $this->plugin->level->addSound(new ClickSound($player->asVector3()));
                        }
                    }
                }
                else {
                    $this->plugin->broadcastMessage("§c> Нужно больше игроков для начала игры!", Arena::MSG_TIP);
                    $this->startTime = 30;
                }
                break;
            case Arena::PHASE_GAME:
            	if($this->gameTime != 0){
                $this->plugin->broadcastMessage("§a> Игроки: [§c".count($this->plugin->vampires)."§a/§e".count($this->plugin->villagers)."§a]. Осталось времени: " . Time::calculateTime($this->gameTime) . "", Arena::MSG_TIP);
                $this->plugin->level->setTime(14000);
                
                foreach($this->plugin->vampires as $vampire){
                	$this->addEffect($vampire, 1, 5, 4);
                	$this->addEffect($vampire, 16, 5, 0);
                }
                foreach($this->plugin->villagers as $villager){
                	$this->addEffect($villager, 15, 5, 0);
                	$this->addEffect($villager, 8, 5, 0);
                }
                
                if($this->plugin->checkEnd()) $this->plugin->startRestart();
                $this->gameTime--;
                }else{
                	$this->plugin->startRestart();
                }
                break;
            case Arena::PHASE_RESTART:
                $this->plugin->broadcastMessage("§a> Перезагрузка через {$this->restartTime} секунд.", Arena::MSG_TIP);
                $this->restartTime--;

                switch ($this->restartTime) {
                    case 0:

                        foreach ($this->plugin->players as $player) {
                            $player->teleport($this->plugin->plugin->getServer()->getDefaultLevel()->getSpawnLocation());

                            $player->getInventory()->clearAll();
                            $player->getArmorInventory()->clearAll();
                            $player->getCursorInventory()->clearAll();

                            $player->setFood(20);
                            $player->setHealth(20);

                            $player->setGamemode($this->plugin->plugin->getServer()->getDefaultGamemode());
                        }
                        $this->plugin->loadArena(true);
                        $this->reloadTimer();
                        break;
                }
                break;
        }
    }

    public function reloadSign() {
        if(!is_array($this->plugin->data["joinsign"]) || empty($this->plugin->data["joinsign"])) return;

        $signPos = Position::fromObject(Vector3::fromString($this->plugin->data["joinsign"][0]), $this->plugin->plugin->getServer()->getLevelByName($this->plugin->data["joinsign"][1]));

        if(!$signPos->getLevel() instanceof Level) return;

        $signText = [
            "§e§lCS:GO",
            "§9[ §b? / ? §9]",
            "§6Настройка",
            "§6Ждите..."
        ];

        if($signPos->getLevel()->getTile($signPos) === null) return;

        if($this->plugin->setup) {
            /** @var Sign $sign */
            $sign = $signPos->getLevel()->getTile($signPos);
            $sign->setText($signText[0], $signText[1], $signText[2], $signText[3]);
            return;
        }

        $signText[1] = "§9[ §b" . count($this->plugin->players) . " / " . $this->plugin->data["slots"] . " §9]";

        switch ($this->plugin->phase) {
            case Arena::PHASE_LOBBY:
                if(count($this->plugin->players) >= $this->plugin->data["slots"]) {
                    $signText[2] = "§6Заполнена";
                    $signText[3] = "§8Карта: §2{$this->plugin->level->getFolderName()}";
                }
                else {
                    $signText[2] = "§aВойти";
                    $signText[3] = "§8Карта: §2{$this->plugin->level->getFolderName()}";
                }
                break;
            case Arena::PHASE_GAME:
                $signText[2] = "§5Идет игра: §4".Time::calculateTime($this->gameTime);
                $signText[3] = "§8Карта: §2{$this->plugin->level->getFolderName()}";
                break;
            case Arena::PHASE_RESTART:
                $signText[2] = "§cПерезагрузка...";
                $signText[3] = "§8Карта: §2{$this->plugin->level->getFolderName()}";
                break;
        }

        /** @var Sign $sign */
        $sign = $signPos->getLevel()->getTile($signPos);
        $sign->setText($signText[0], $signText[1], $signText[2], $signText[3]);
    }

    public function reloadTimer() {
        $this->startTime = 30; //$this->plugin->data["wait_time"];
        $this->gameTime = 180; //$this->plugin->data["game_time"];
        $this->restartTime = 10; //$this->plugin->data["restart_time"];
    }
    
    public function addEffect($entity, $id, $duration = 5, $lvl = 0){
    	$effect = Effect::getEffect((int)$id);
		$entity->addEffect(new EffectInstance($effect, $duration * 20, $lvl, true));
    }
}
