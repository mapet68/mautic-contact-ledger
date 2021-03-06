<?php

/*
 * @copyright   2018 Mautic Contributors. All rights reserved
 * @author      Mautic Community
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\MauticContactLedgerBundle\Entity;

use Doctrine\Common\Cache\FilesystemCache;
use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Types\Type;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * Class LedgerEntryRepository.
 */
class LedgerEntryRepository extends CommonRepository
{
    const MAUTIC_CONTACT_LEDGER_STATUS_CONVERTED = 'converted';

    const MAUTIC_CONTACT_LEDGER_STATUS_DECLINED  = 'rejected';

    const MAUTIC_CONTACT_LEDGER_STATUS_ENHANCED  = 'received';

    const MAUTIC_CONTACT_LEDGER_STATUS_RECEIVED  = 'received';

    const MAUTIC_CONTACT_LEDGER_STATUS_SCRUBBED  = 'received';

    /**
     * @param $dollarValue
     *
     * @return string
     */
    public static function formatDollar($dollarValue)
    {
        return sprintf('%19.4f', floatval($dollarValue));
    }

    /**
     * @return string
     */
    public function getTableAlias()
    {
        return 'cle';
    }

    /**
     * @param Campaign  $campaign
     * @param \DateTime $dateFrom
     * @param \DateTime $dateTo
     * @param           $unit
     * @param           $dbunit
     * @param           $cache_dir
     *
     * @return array
     *
     * @throws \Doctrine\DBAL\DBALException
     */
    public function getCampaignRevenueData(
        Campaign $campaign,
        \DateTime $dateFrom,
        \DateTime $dateTo,
        $unit,
        $dbunit,
        $cache_dir = __DIR__
    ) {
        $resultDateTime = null;
        $results        = [];
        $sqlFrom        = new \DateTime($dateFrom->format('Y-m-d'));
        $sqlFrom->modify('midnight')->setTimeZone(new \DateTimeZone('UTC'));

        $sqlTo = new \DateTime($dateTo->format('Y-m-d'));
        $sqlTo->modify('midnight +1 day')->setTimeZone(new \DateTimeZone('UTC'));

        $builder = $this->getEntityManager()->getConnection()->createQueryBuilder();

        $userTZ     = new \DateTime('now');
        $interval   = abs($userTZ->getOffset() / 3600);
        $selectExpr = !in_array($unit, ['H', 'i', 's']) ?
            "DATE_FORMAT(date_added,  '$dbunit')           as label" :
            "DATE_FORMAT(DATE_SUB(date_added, INTERVAL $interval HOUR), '$dbunit')           as label";

        $builder
            ->select(
                $selectExpr,
                'SUM(IFNULL(cost, 0.0))                      as cost',
                'SUM(IFNULL(revenue, 0.0))                   as revenue',
                'SUM(IFNULL(revenue, 0.0))-SUM(IFNULL(cost, 0.0)) as profit'
            )
            ->from('contact_ledger')
            ->where(
                $builder->expr()->eq(':campaign_id', 'campaign_id'),
                $builder->expr()->lte(':fromDate', 'date_added'),
                $builder->expr()->gt(':toDate', 'date_added')
            );

        $builder->groupBy("DATE_FORMAT(date_added, '$dbunit')")
            ->orderBy('label', 'ASC');

        // query the database
        $builder->setParameter(':campaign_id', $campaign->getId(), Type::INTEGER);
        $builder->setParameter(':fromDate', $sqlFrom, Type::DATETIME);
        $builder->setParameter(':toDate', $sqlTo, Type::DATETIME);

        $cache = new FilesystemCache($cache_dir.'/sql');

        $stmt = $builder->getConnection()->executeCacheQuery(
            $builder->getSQL(),
            $builder->getParameters(),
            $builder->getParameterTypes(),
            new QueryCacheProfile(900, 'campaign-revenue-queries', $cache)
        );

        $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $stmt->closeCursor();

        return $results;
    }

    /**
     * @param      $params
     * @param bool $bySource
     *
     * @return array
     */
    public function getDashboardRevenueWidgetData($params, $bySource = false, $cache_dir = __DIR__)
    {
        $statBuilder = $this->getEntityManager()->getConnection()->createQueryBuilder();
        $statBuilder
            ->select(
                'ss.campaign_id',
                'c.is_published',
                'c.name',
                'SUM(IF(ss.type IS NULL                     , 0, 1)) AS received',
                "SUM(IF(ss.type IN ('accepted' , 'scrubbed'), 0, 1)) AS rejected",
                "SUM(IF(ss.type = 'accepted'                , 1, 0)) AS converted",
                "SUM(IF(ss.type = 'scrubbed'                , 1, 0)) AS scrubbed",
                'IFNULL(clc.cost, 0)                                 AS cost',
                'IFNULL(clr.revenue, 0)                              AS revenue'
            )
            ->from(MAUTIC_TABLE_PREFIX.'contactsource_stats', 'ss')
            ->join('ss', MAUTIC_TABLE_PREFIX.'campaigns', 'c', 'c.id = ss.campaign_id')
            ->where('ss.date_added BETWEEN :dateFrom AND :dateTo')
            ->groupBy('ss.campaign_id, c.is_published, c.name, clc.cost, clr.revenue')
            ->orderBy('COUNT(ss.campaign_id)', 'ASC');
        $costBuilder = $this->getEntityManager()->getConnection()->createQueryBuilder();
        $costBuilder
            ->select('lc.campaign_id', 'SUM(lc.cost) AS cost')
            ->from(MAUTIC_TABLE_PREFIX.'contact_ledger', 'lc')
            ->groupBy('lc.campaign_id');
        $costJoinCond = 'clc.campaign_id = ss.campaign_id';
        $revBuilder   = $this->getEntityManager()->getConnection()->createQueryBuilder();
        $revBuilder
            ->select('lr.campaign_id', 'SUM(lr.revenue) AS revenue')
            ->from(MAUTIC_TABLE_PREFIX.'contact_ledger', 'lr')
            ->groupBy('lr.campaign_id');
        $revJoinCond = 'clr.campaign_id = ss.campaign_id';
        if ($bySource) {
            $statBuilder
                ->addSelect('ss.contactsource_id', 'cs.name as source')
                ->join('ss', MAUTIC_TABLE_PREFIX.'contactsource', 'cs', 'cs.id = ss.contactsource_id')
                ->addGroupBy('ss.contactsource_id, cs.name');
            $costBuilder
                ->addSelect('sc.contactsource_id')
                ->innerJoin(
                    'lc',
                    'contactsource_stats',
                    'sc',
                    'lc.campaign_id = sc.campaign_id AND lc.contact_id = sc.contact_id'
                )
                ->addGroupBy('sc.contactsource_id');
            $costJoinCond .= ' AND clc.contactsource_id = ss.contactsource_id';
            $revBuilder
                ->addSelect('sr.contactsource_id')
                ->innerJoin(
                    'lr',
                    'contactsource_stats',
                    'sr',
                    'lr.campaign_id = sr.campaign_id AND lr.contact_id = sr.contact_id'
                )
                ->addGroupBy('sr.contactsource_id');
            $revJoinCond .= ' AND clr.contactsource_id = ss.contactsource_id';
        }
        $statBuilder
            ->leftJoin('ss', '('.$costBuilder->getSQL().')', 'clc', $costJoinCond)
            ->leftJoin('ss', '('.$revBuilder->getSQL().')', 'clr', $revJoinCond);
        $statBuilder
            ->setParameter('dateFrom', $params['dateFrom'])
            ->setParameter('dateTo', $params['dateTo']);
        if (isset($params['limit']) && (0 < $params['limit'])) {
            $statBuilder->setMaxResults($params['limit']);
        }
        $results = ['rows' => []];

        // setup cache
        $cache = new FilesystemCache($cache_dir.'/sql');
        $statBuilder->getConnection()->getConfiguration()->setResultCacheImpl($cache);
        $stmt       = $statBuilder->getConnection()->executeCacheQuery(
            $statBuilder->getSQL(),
            $statBuilder->getParameters(),
            $statBuilder->getParameterTypes(),
            new QueryCacheProfile(900, 'dashboard-revenue-queries', $cache)
        );
        $financials = $stmt->fetchAll();
        $stmt->closeCursor();
        foreach ($financials as $financial) {
            // must be ordered as active, id, name, received, converted, revenue, cost, gm, margin, ecpm
            $financial['revenue']      = number_format(floatval($financial['revenue']), 2, '.', ',');
            $financial['cost']         = number_format(floatval($financial['cost']), 2, '.', ',');
            $financial['gross_income'] = number_format(
                (float) $financial['revenue'] - (float) $financial['cost'],
                2,
                '.',
                ','
            );

            if ($financial['gross_income'] > 0) {
                $financial['gross_margin'] = number_format(
                    100 * $financial['gross_income'] / $financial['revenue'],
                    0,
                    '.',
                    ','
                );
                $financial['ecpm']         = number_format((float) $financial['gross_income'] / 1000, 4, '.', ',');
            } else {
                $financial['gross_margin'] = 0;
                $financial['ecpm']         = 0;
            }

            $result = [
                $financial['is_published'],
                $financial['campaign_id'],
                $financial['name'],
            ];
            if ($bySource) {
                $result[] = $financial['contactsource_id'];
                $result[] = $financial['source'];
            }
            $result[] = $financial['received'];
            $result[] = $financial['scrubbed'];
            $result[] = $financial['rejected'];
            $result[] = $financial['converted'];
            $result[] = $financial['revenue'];
            $result[] = $financial['cost'];
            $result[] = $financial['gross_income'];
            $result[] = $financial['gross_margin'];
            $result[] = $financial['ecpm'];

            $results['rows'][] = $result;
        }

        return $results;
    }
}
