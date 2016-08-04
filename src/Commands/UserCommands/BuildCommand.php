<?php
namespace Longman\TelegramBot\Commands\SystemCommands;

use TriviWars\Req;
use TriviWars\DB\TriviDB;
use TriviWars\Entity\PlanetBuilding;
use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\DB;
use Longman\TelegramBot\Entities\ReplyKeyboardMarkup;
use Longman\TelegramBot\Conversation;

class BuildCommand extends UserCommand
{
    /**#@+
     * {@inheritdoc}
     */
    protected $name = 'build';
    protected $description = 'Manage buildings on the planet';
    protected $usage = '/build';
    protected $version = '1.0.0';
    /**#@-*/

    /**
     * {@inheritdoc}
     */
    public function execute()
    {
        $message = $this->getMessage();
        $user_id = $message->getFrom()->getId();
        $chat_id = $message->getChat()->getId();

        $conversation = new Conversation($user_id, $chat_id, 'build');

        // Get current planet
        $em = TriviDB::getEntityManager();
        $planet = $em->getRepository('TW:Planet')->findOneBy(array('player' => $em->getReference('TW:Player', $user_id)));
        $planet->update();
        $em->merge($planet);
        $em->flush();

        // If the command is not for the list, it's an upgrade
        $command = trim($message->getText(true));
        if ($command != '🏭 Buildings') {
            // Go back to status command
            if ($command == '🔙 Back') {
                $conversation->cancel();
                return $this->telegram->executeCommand('status');
            }

            // Get buildings
            $building = $em->getRepository('TW:Building')->findOneBy(array('name' => $command));
            if (empty($building)) {
                return Req::error($chat_id, 'Invalid building name');
            }

            // Get current building level or create it if not found
            $planetBuilding = $em->getRepository('TW:PlanetBuilding')->findOneBy(array('planet' => $planet, 'building' => $building));
            if (empty($planetBuilding)) {
                $planetBuilding = new PlanetBuilding();
                $planetBuilding->setBuilding($building);
                $planetBuilding->setPlanet($planet);
                $planetBuilding->setLevel(0);
            }

            // Pay the cost
            $price = $building->getPriceForLevel($planetBuilding->getLevel() + 1);
            if (!$planet->canPay($price)) {
                return Req::error($chat_id, 'You do not have enough resources');
            }
            $planet->pay($price);
            $em->merge($planet);

            // Update building level
            // TODO: timer to upgrade instead of instant
            $planetBuilding->setLevel($planetBuilding->getLevel() + 1);
            $em->merge($planetBuilding);

            $em->flush();

            $conversation->stop();

            Req::success($chat_id, 'Up '.$building->getName());
            return $this->telegram->executeCommand('status');
        }

        // Get buildings and their levels
        $buildings = $em->getRepository('TW:Building')->findAll();
        $planetBuildings = $em->getRepository('TW:PlanetBuilding')->findBy(array('planet' => $planet));
        $levels = [];
        foreach ($planetBuildings as $building) {
            $b = $building->getBuilding();
            if (empty($b)) {
                continue;
            }
            $levels[$b->getId()] = $building->getLevel();
        }

        // Generate reply text
        $text = '';
        foreach ($buildings as $i => $building) {
            if ($i > 0) {
                $text .= "\n\n";
            }

            // Get building information and costs
            $id = $building->getId();
            $currentLevel = isset($levels[$id]) ? $levels[$id] : 0;
            $price = $building->getPriceForLevel($currentLevel + 1);
            $conso = $building->getConsumptionForLevel($currentLevel + 1);
            $consoCurrent = $building->getConsumptionForLevel($currentLevel);
            $prod = $building->getProductionForLevel($currentLevel + 1);
            $prodCurrent = $building->getProductionForLevel($currentLevel);
            $energy = $building->getEnergyForLevel($currentLevel + 1);
            $energyCurrent = $building->getEnergyForLevel($currentLevel);

            // Name
            $name = $building->getName().' ('.($currentLevel + 1).')';

            // Production
            $production  = $this->displayUnit('💰', $prod[0], $prodCurrent[0]);
            $production .= $this->displayUnit('🌽', $prod[1], $prodCurrent[1]);
            $production .= $this->displayUnit('⚡', $energy, $energyCurrent);

            // Price
            $cost  = $this->displayUnit('💰', $price[0]);
            $cost .= $this->displayUnit('🌽', $price[1]);
            $cost .= $this->displayUnit('⚡', $conso, $consoCurrent);

            $text .= $name . (!empty($production) ? ' - '.$production : '') . "\n" . $cost;
        }

        // Generate keyboard with 3 buildings per line
        $keyboard = [];
        $curr = [];
        $i = 0;
        foreach ($buildings as $i => $building) {
            $curr[] = $building->getName();

            if ($i % 3 == 2 && $i != 0) {
                $keyboard[] = $curr;
                $curr = [];
            }
        }
        if ($i % 3 != 2) {
            $keyboard[] = $curr;
        }
        $keyboard[] = ['🔙 Back'];

        $markup = new ReplyKeyboardMarkup([
            'keyboard'          => $keyboard,
            'resize_keyboard'   => true,
            'one_time_keyboard' => true,
            'selective'         => false
        ]);
        return Req::send($chat_id, $text, $markup);
    }

    protected function displayUnit($unit, $prod, $current = null)
    {
        $ret = '';
        if (!empty($prod)) {
            $ret .= ' '.$unit.$prod;
            if ($current !== null) {
                if ($prod != $current) {
                    $ret .= ' (+'.($prod - $current).')';
                } else {
                    $ret .= ' (=)';
                }
            }
        }
        return trim($ret);
    }
}
