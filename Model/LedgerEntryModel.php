<?php

/*
 * @copyright   2018 Mautic Contributors. All rights reserved
 * @author      Mautic Community
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\MauticContactLedgerBundle\Model;

use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CoreBundle\Helper\Chart\ChartQuery;
use Mautic\CoreBundle\Model\AbstractCommonModel;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\MauticContactLedgerBundle\Entity\LedgerEntry;

/**
 * class LedgerEntryModel.
 */
class LedgerEntryModel extends AbstractCommonModel
{
    /**
     * @param Lead              $lead
     * @param Campaign|null     $campaign
     * @param array|object|null $actor
     * @param string            $activity
     * @param string|float|null $cost
     * @param string|float|null $revenue
     * @param string|null       $memo
     */
    public function addEntry(
        Lead $lead,
        Campaign $campaign = null,
        $actor = null,
        $activity = null,
        $cost = null,
        $revenue = null,
        $memo = null
    ) {
        $bundleName = $className = $objectId = null;

        if (is_array($actor)) {
            list($bundleName, $className, $objectId) = $this->getActorFromArray($actor);
        } elseif (is_object($actor)) {
            list($bundleName, $className, $objectId) = $this->getActorFromObject($actor);
        }

        $entry = new LedgerEntry();
        $entry
            ->setDateAdded(new \DateTime())
            ->setContact($lead)
            ->setCampaign($campaign)
            ->setBundleName($bundleName)
            ->setClassName($className)
            ->setObjectId($objectId)
            ->setActivity($activity)
            ->setMemo($memo)
            ->setCost($cost)
            ->setRevenue($revenue);
        $this->em->persist($entry);
    }

    /**
     * @param array $array
     *
     * @return array
     */
    protected function getActorFromArray(array $array)
    {
        $entryObject = [null, null, null];

        $entryObject[2] = array_pop($array); //id
        $entryObject[1] = array_pop($array); //Class
        if (!empty($array)) {
            $entryObject[0] = array_pop($array); //Bundle
        } else { //the hard way
            foreach (get_declared_classes() as $namespaced) {
                $pathParts = explode('\\', $namespaced);
                $className = array_pop($pathParts);
                if ($className === $entryObject[1]) {
                    foreach ($pathParts as $pathPart) {
                        if (false !== strstr($pathPart, 'Bundle')) {
                            $entryObject[0] = $pathPart;
                            break 2;
                        }
                    }
                }
            }
        }

        return $entryObject;
    }

    /**
     * @param object $object
     *
     * @return array
     */
    protected function getActorFromObject($object)
    {
        $entryObject = [null, null, null];

        if (is_object($object)) {
            $entryObject[2] = $object->getId();
            $pathParts      = explode('\\', get_class($object));
            $entryObject[1] = array_pop($pathParts);
            foreach ($pathParts as $pathPart) {
                if (strstr($pathPart, 'Bundle')) {
                    $entryObject[0] = $pathPart;
                    break;
                }
            }
        }

        return $entryObject;
    }

    /**
     * @param Campaign  $campaign
     * @param \DateTime $dateFrom
     * @param \DateTime $dateTo
     *
     * @return array
     *
     * @throws \Doctrine\DBAL\DBALException
     */
    public function getCampaignRevenueChartData(Campaign $campaign, \DateTime $dateFrom, \DateTime $dateTo)
    {
        $chartData = ['labels' => [], 'datasets' => []];
        $labels    = $costs = $revenues = $profits = [];
        $cache_dir = $this->dispatcher->getContainer()->getParameter('kernel.cache_dir');

        $unit             = $this->getTimeUnitFromDateRange($dateFrom, $dateTo);
        $chartQueryHelper = new ChartQuery($this->em->getConnection(), $dateFrom, $dateTo, $unit);
        $dbunit           = $chartQueryHelper->translateTimeUnit($unit);
        $dbunit           = '%Y %U' == $dbunit ? '%Y week %u' : $dbunit;
        $dbunit           = '%Y-%m' == $dbunit ? '%M %Y' : $dbunit;

        $data = $this->getRepository()->getCampaignRevenueData($campaign, $dateFrom, $dateTo, $unit, $dbunit, $cache_dir);

        // fix when only 1 result
        if (1 == count($data)) {
            $data = $this->fixSingleResultForCharts($data, $unit, $dbunit);
        }

        if (!empty($data)) {
            $defaultDollars = self::formatDollar(0);
            foreach ($data as $item) {
                $labels[] = $item['label'];

                $costs[]    = self::formatDollar(-$item['cost']);
                $revenues[] = self::formatDollar($item['revenue']);
                $profits[]  = self::formatDollar($item['profit']);
            }

            $chartData = [
                'labels'   => $labels,
                'datasets' => [
                    [
                        'label'                     => 'Cost',
                        'data'                      => $costs,
                        'backgroundColor'           => 'rgba(204,51,51,0.1)',
                        'borderColor'               => 'rgba(204,51,51,0.8)',
                        'pointHoverBackgroundColor' => 'rgba(204,51,51,0.75)',
                        'pointHoverBorderColor'     => 'rgba(204,51,51,1)',
                    ],
                    [
                        'label'                     => 'Reveue',
                        'data'                      => $revenues,
                        'backgroundColor'           => 'rgba(51,51,51,0.1)',
                        'borderColor'               => 'rgba(51,51,51,0.8)',
                        'pointHoverBackgroundColor' => 'rgba(51,51,51,0.75)',
                        'pointHoverBorderColor'     => 'rgba(51,51,51,1)',
                    ],
                    [
                        'label'                     => 'Profit',
                        'data'                      => $profits,
                        'backgroundColor'           => 'rgba(51,204,51,0.1)',
                        'borderColor'               => 'rgba(51,204,51,0.8)',
                        'pointHoverBackgroundColor' => 'rgba(51,204,51,0.75)',
                        'pointHoverBorderColor'     => 'rgba(51,204,51,1)',
                    ],
                ],
            ];
        }

        return $chartData;
    }

    /**
     * Returns appropriate time unit from a date range so the line/bar charts won't be too full/empty.
     *
     * @param $dateFrom
     * @param $dateTo
     *
     * @return string
     */
    public function getTimeUnitFromDateRange($dateFrom, $dateTo)
    {
        $dayDiff = $dateTo->diff($dateFrom)->format('%a');
        $unit    = 'd';

        if ($dayDiff <= 1) {
            $unit = 'H';

            $sameDay    = $dateTo->format('d') == $dateFrom->format('d') ? 1 : 0;
            $hourDiff   = $dateTo->diff($dateFrom)->format('%h');
            $minuteDiff = $dateTo->diff($dateFrom)->format('%i');
            if ($sameDay && !intval($hourDiff) && intval($minuteDiff)) {
                $unit = 'i';
            }
            $secondDiff = $dateTo->diff($dateFrom)->format('%s');
            if (!intval($minuteDiff) && intval($secondDiff)) {
                $unit = 'i';
            }
        }
        if ($dayDiff > 31) {
            $unit = 'W';
        }
        if ($dayDiff > 100) {
            $unit = 'm';
        }
        if ($dayDiff > 1000) {
            $unit = 'Y';
        }

        return $unit;
    }

    /**
     * @return bool|\Doctrine\ORM\EntityRepository|\MauticPlugin\MauticContactLedgerBundle\Entity\LedgerEntryRepository
     */
    public function getRepository()
    {
        return $this->em->getRepository('MauticContactLedgerBundle:LedgerEntry');
    }

    protected function fixSingleResultForCharts($results, $unit, $dbunit)
    {
        $unitStrings = [
            'H' => '1 Hour',
            'W' => '1 Week',
            'D' => '1 Day',
            'm' => '1 Month',
            'i' => '1 Minute',
            's' => '1 Second',
            'Y' => '1 Year',
        ];

        $unitBefore = date_sub(
            new \DateTime($results[0]['label']),
            date_interval_create_from_date_string($unitStrings[$unit])
        );
        $unitAfter  = date_add(
            new \DateTime($results[0]['label']),
            date_interval_create_from_date_string($unitStrings[$unit])
        );
        array_unshift(
            $results,
            [
                'cost'    => '0',
                'label'   => $unitBefore->format(str_replace('%', '', $dbunit)),
                'profit'  => '0',
                'revenue' => '0',
            ]
        );
        array_push(
            $results,
            [
                'cost'    => '0',
                'label'   => $unitAfter->format(str_replace('%', '', $dbunit)),
                'profit'  => '0',
                'revenue' => '0',
            ]
        );

        return $results;
    }

    /**
     * @param mixed $dollarValue
     *
     * @return string
     */
    public static function formatDollar($dollarValue)
    {
        return sprintf('%0.2f', floatval($dollarValue));
    }

    /**
     * @param Campaign  $campaign
     * @param \DateTime $dateFrom
     * @param \DateTime $dateTo
     *
     * @return array
     *
     * @throws \Doctrine\DBAL\DBALException
     */
    public function getCampaignRevenueDatatableData(Campaign $campaign, \DateTime $dateFrom, \DateTime $dateTo)
    {
        $response = [];

        $cache_dir = $this->dispatcher->getContainer()->getParameter('kernel.cache_dir');

        $unit             = $this->getTimeUnitFromDateRange($dateFrom, $dateTo);
        $chartQueryHelper = new ChartQuery($this->em->getConnection(), $dateFrom, $dateTo, $unit);
        $dbunit           = $chartQueryHelper->translateTimeUnit($unit);
        $dbunit           = '%Y %U' == $dbunit ? '%Y week %u' : $dbunit;
        $dbunit           = '%Y-%m' == $dbunit ? '%M %Y' : $dbunit;

        $results = $this->getRepository()->getCampaignRevenueData($campaign, $dateFrom, $dateTo, $unit, $dbunit, $cache_dir);

        foreach ($results as $result) {
            $result['label']   = $result['label'];
            $result['cost']    = self::formatDollar($result['cost']);
            $result['revenue'] = self::formatDollar($result['revenue']);
            $result['profit']  = self::formatDollar($result['profit']);
            $response[]        = $result;
        }

        return $response;
    }

    /**
     * @param mixed $params
     *
     * @return mixed
     */
    public function getDashboardRevenueWidgetData($params)
    {
        return $this->getRepository()->getDashboardRevenueWidgetData($params);
    }
}
