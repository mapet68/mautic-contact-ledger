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

use Doctrine\DBAL\Types\Type;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * Class LedgerEntryRepository.
 */
class LedgerEntryRepository extends CommonRepository
{
    const MAUTIC_CONTACT_LEDGER_STATUS_CONVERTED = 'converted';

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
     *
     * @return array
     */
    public function getCampaignRevenueData(Campaign $campaign, \DateTime $dateFrom, \DateTime $dateTo)
    {
        $resultDateTime = null;
        $results        = [];

        $sqlFrom = new \DateTime($dateFrom->format('Y-m-d'));
        $sqlFrom->modify('midnight');

        $sqlTo = new \DateTime($dateTo->format('Y-m-d'));
        $sqlTo->modify('midnight +1 day');

        $builder = $this->getEntityManager()->getConnection()->createQueryBuilder();
        $builder
            ->select(
                'DATE_FORMAT(date_added, "%Y%m%d")           as label',
                'SUM(IFNULL(cost, 0.0))                      as cost',
                'SUM(IFNULL(revenue, 0.0))                   as revenue',
                'SUM(IFNULL(revenue, 0.0))-SUM(IFNULL(cost, 0.0)) as profit'
            )
            ->from('contact_ledger')
            ->where(
                $builder->expr()->eq('?', 'campaign_id'),
                $builder->expr()->lte('?', 'date_added'),
                $builder->expr()->gt('?', 'date_added')
            )
            ->groupBy('label')
            ->orderBy('label', 'ASC');

        $stmt = $this->getEntityManager()->getConnection()->prepare(
            $builder->getSQL()
        );

        // query the database
        $stmt->bindValue(1, $campaign->getId(), Type::INTEGER);
        $stmt->bindValue(2, $sqlFrom, Type::DATETIME);
        $stmt->bindValue(3, $sqlTo, Type::DATETIME);
        $stmt->execute();

        if (0 < $stmt->rowCount()) {
            $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }

        return $results;
    }

    /**
     * @param $params
     *
     * @return array
     */
    public function getDashboardRevenueWidgetData($params)
    {
        $results = $financials = [];

        // first get a count of leads that were ingested during selected date range
        $q = $this->_em->getConnection()->createQueryBuilder()
            ->select('COUNT(l.id) as count')
            ->from(MAUTIC_TABLE_PREFIX.'leads', 'l');

        if ($params['dateFrom']) {
            $date = date_create($params['dateFrom']);
            date_sub($date, date_interval_create_from_date_string('1 days'));
            $params['dateFrom'] = date_format($date, 'Y-m-d');
            $q->where(
                $q->expr()->gte('l.date_added', ':dateFrom')
            );
            $q->setParameter('dateFrom', $params['dateFrom']);
        }

        if ($params['dateTo']) {
            $date = date_create($params['dateTo']);
            date_add($date, date_interval_create_from_date_string('1 days'));
            $params['dateTo'] = date_format($date, 'Y-m-d');
            if (!$params['dateFrom']) {
                $q->where(
                    $q->expr()->lte('l.date_added', ':dateTo')
                );
            } else {
                $q->andWhere(
                    $q->expr()->lte('l.date_added', ':dateTo')
                );
            }
            $q->setParameter('dateTo', $params['dateTo']);
        }
        $count = $q->execute()->fetch();
        // now get ledger data for selected leads
        if ($count['count']) {
            // get the actual IDs to use from this date range

            $q->resetQueryPart('select');
            $q->select('l.id');

            $leads = $q->execute()->fetchAll();
            $leads = array_column($leads, 'id');

            // get financials from ledger based on returned Lead list
            $f = $this->_em->getConnection()->createQueryBuilder();
            $f->select(
                'c.name, c.is_published, c.id as campaign_id, SUM(cl.cost) as cost, SUM(cl.revenue) as revenue, COUNT(DISTINCT(cl.contact_id)) as received'
            )->from(MAUTIC_TABLE_PREFIX.'contact_ledger', 'cl');

            $f->where(
                $f->expr()->in('cl.contact_id', $leads)
            );

            $f->groupBy('cl.campaign_id');

            // join Campaign table to get name and publish status
            $f->join('cl', MAUTIC_TABLE_PREFIX.'campaigns', 'c', 'c.id = cl.campaign_id');

            $f->orderBy('COUNT(cl.contact_id)', 'DESC');

            if (isset($params['limit'])) {
                $f->setMaxResults($params['limit']);
            }

            $financials = $f->execute()->fetchAll();

            // get conversions from ledger based on class and activity
            $converted = $this->getConvertedByCampaign($leads);

            $received = $this->getReceivedByCampaign($leads);

            foreach ($financials as $financial) {
                // must be ordered as active, id, name, received, converted, revenue, cost, gm, margin, ecpm
                $financial['revenue']   = floatval($financial['revenue']);
                $financial['cost']      = floatval($financial['cost']);
                $financial['gm']        = $financial['revenue'] - $financial['cost'];
                $financial['margin']    = $financial['revenue'] ? number_format(
                    ($financial['gm'] / $financial['revenue']) * 100,
                    2,
                    '.',
                    ','
                ) : 0;
                $financial['ecpm']      = number_format($financial['gm'] / 1000, 4, '.', ',');
                $financial['received']  = intval($received[$financial['campaign_id']]['sum']);
                $financial['converted'] = intval($converted[$financial['campaign_id']]['sum']);
                $results['rows'][]      = [
                    $financial['is_published'],
                    $financial['campaign_id'],
                    $financial['name'],
                    $financial['received'],
                    $financial['converted'],
                    $financial['revenue'],
                    $financial['cost'],
                    $financial['gm'],
                    $financial['margin'],
                    $financial['ecpm'],
                ];
                //
                // $results['summary']['gmTotal']        = $results['summary']['gmTotal'] + $financial['gm'];
                // $results['summary']['costTotal']      = $results['summary']['costTotal'] + $financial['cost'];
                // $results['summary']['revenueTotal']   = $results['summary']['revenueTotal'] + $financial['revenue'];
                // $results['summary']['ecpmTotal']      = $results['summary']['gmTotal'] / 1000;
                // $results['summary']['marginTotal']    = $results['summary']['revenueTotal'] ? ($results['summary']['gmTotal'] / $results['summary']['revenueTotal']) * 100 : 0;
                // $results['summary']['receivedTotal']  = $results['summary']['receivedTotal'] + $financial['received'];
                // $results['summary']['convertedTotal'] = $results['summary']['convertedTotal'] + $financial['converted'];
            }
        }

        return $results;
    }

    /**
     * @param array $leads
     *
     * @return mixed
     */
    private function getConvertedByCampaign($leads = [])
    {
        // get conversions from ledger based on class and activity
        $c = $this->_em->getConnection()->createQueryBuilder();
        $c->select('COUNT(cl.activity) as converted, cl.campaign_id, ie.integration_entity_id as source')
            ->from(MAUTIC_TABLE_PREFIX.'contact_ledger', 'cl');

        $c->groupBy('cl.campaign_id, ie.integration_entity_id');

        $c->join(
            'cl',
            MAUTIC_TABLE_PREFIX.'integration_entity',
            'ie',
            'cl.contact_id = ie.internal_entity_id AND ie.internal_entity = :lead AND ie.integration_entity = :ContactSource'
        );
        $c->setParameter('ContactSource', 'ContactSource');
        $c->setParameter('lead', 'lead');

        $c->where(
            $c->expr()->in('cl.contact_id', $leads)
        );
        $c->andWhere(
            $c->expr()->eq('cl.class_name', ':ContactClient'),
            $c->expr()->eq('cl.activity', ':MAUTIC_CONVERSION_LABEL')
        );
        $c->setParameter('ContactClient', 'ContactClient');
        $c->setParameter('MAUTIC_CONVERSION_LABEL', self::MAUTIC_CONTACT_LEDGER_STATUS_CONVERTED);

        $results     = $c->execute()->fetchAll();
        $campaignSum = [];

        foreach ($results as $row) {
            $campaignSum[$row['campaign_id']]['sum']          = isset($campaignSum[$row['campaign_id']]['sum']) ? $campaignSum[$row['campaign_id']]['sum'] += $row['converted'] : $row['converted'];
            $campaignSum[$row['campaign_id']][$row['source']] = $row['converted'];
        }

        return $campaignSum;
    }

    /**
     * @param array $leads
     *
     * @return array
     */
    private function getReceivedByCampaign($leads = [])
    {
        if (!empty($leads)) {
            $r = $this->_em->getConnection()->createQueryBuilder();
            $r->select(
                'cal.campaign_id as campaign, ie.integration_entity_id as source_id, COUNT(ie.integration_entity_id) as source'
            )
                ->from(MAUTIC_TABLE_PREFIX.'campaign_leads', 'cal');

            $r->groupBy('ie.integration_entity_id, cal.campaign_id');
            $r->where(
                $r->expr()->in('cal.lead_id', $leads)
            );

            $r->join(
                'cal',
                MAUTIC_TABLE_PREFIX.'integration_entity',
                'ie',
                'cal.lead_id = ie.internal_entity_id AND ie.internal_entity = :lead AND ie.integration_entity = :ContactSource'
            );
            $r->setParameter('ContactSource', 'ContactSource');
            $r->setParameter('lead', 'lead');

            $results     = $r->execute()->fetchAll();
            $campaignSum = [];

            foreach ($results as $row) {
                $campaignSum[$row['campaign']]['sum']             = isset($campaignSum[$row['campaign']]['sum']) ? $campaignSum[$row['campaign']]['sum'] += $row['source'] : $row['source'];
                $campaignSum[$row['campaign']][$row['source_id']] = $row['source'];
            }

            return $campaignSum;
        }
    }

    /**
     * @param $params
     *
     * @return array
     */
    public function getDashboardSourceRevenueWidgetData($params)
    {
        $results = $financials = [];

        // first get a count of leads that were ingested during selected date range
        $q = $this->_em->getConnection()->createQueryBuilder()
            ->select('COUNT(l.id) as count')
            ->from(MAUTIC_TABLE_PREFIX.'leads', 'l');

        if ($params['dateFrom']) {
            $date = date_create($params['dateFrom']);
            date_sub($date, date_interval_create_from_date_string('1 days'));
            $params['dateFrom'] = date_format($date, 'Y-m-d');
            $q->where(
                $q->expr()->gte('l.date_added', ':dateFrom')
            );
            $q->setParameter('dateFrom', $params['dateFrom']);
        }

        if ($params['dateTo']) {
            $date = date_create($params['dateTo']);
            date_add($date, date_interval_create_from_date_string('1 days'));
            $params['dateTo'] = date_format($date, 'Y-m-d');
            if (!$params['dateFrom']) {
                $q->where(
                    $q->expr()->lte('l.date_added', ':dateTo')
                );
            } else {
                $q->andWhere(
                    $q->expr()->lte('l.date_added', ':dateTo')
                );
            }
            $q->setParameter('dateTo', $params['dateTo']);
        }

        $count = $q->execute()->fetch();
        // now get ledger data for selected leads
        if ($count['count']) {
            // get the actual IDs to use from this date range

            $q->resetQueryPart('select');
            $q->select('l.id');

            $leads = $q->execute()->fetchAll();
            $leads = array_column($leads, 'id');

            // get financials from ledger based on returned Lead list
            $f = $this->_em->getConnection()->createQueryBuilder();
            $f->select(
                'c.name as campaign_name, c.is_published, c.id as campaign_id, SUM(cl.cost) as cost, SUM(cl.revenue) as revenue, cs.id as source_id, cs.name as source_name'
            )->from(MAUTIC_TABLE_PREFIX.'contact_ledger', 'cl');

            $f->where(
                $f->expr()->in('cl.contact_id', $leads)
            );

            $f->groupBy('cl.campaign_id, cs.id');

            // join Campaign table to get name and publish status
            $f->join('cl', MAUTIC_TABLE_PREFIX.'campaigns', 'c', 'c.id = cl.campaign_id');

            // join Integration table to get contact source from lead mapping
            $f->join(
                'cl',
                MAUTIC_TABLE_PREFIX.'integration_entity',
                'i',
                'cl.contact_id = i.internal_entity_id AND i.internal_entity = :lead AND i.integration_entity = :ContactClientSource'
            );
            $f->setParameter('ContactClientSource', 'ContactSource');
            $f->setParameter('lead', 'lead');

            // join Contact Source table to get contact source name
            $f->join('cl', MAUTIC_TABLE_PREFIX.'contactsource', 'cs', 'i.integration_entity_id = cs.id');

            $f->orderBy('COUNT(cl.contact_id)', 'DESC');

            if (isset($params['limit'])) {
                $f->setMaxResults($params['limit']);
            }

            $financials = $f->execute()->fetchAll();

            $converted = $this->getConvertedByCampaign($leads);

            $received = $this->getReceivedByCampaign($leads);

            foreach ($financials as $financial) {
                // must be ordered as active, id, name, received, converted, revenue, cost, gm, margin, ecpm
                $financial['revenue']   = floatval($financial['revenue']);
                $financial['cost']      = floatval($financial['cost']);
                $financial['gm']        = $financial['revenue'] - $financial['cost'];
                $financial['margin']    = $financial['revenue'] ? number_format(
                    ($financial['gm'] / $financial['revenue']) * 100,
                    2,
                    '.',
                    ','
                ) : 0;
                $financial['ecpm']      = number_format($financial['gm'] / 1000, 4, '.', ',');
                $financial['received']  = intval($received[$financial['campaign_id']][$financial['source_id']]);
                $financial['converted'] = intval($converted[$financial['campaign_id']][$financial['source_id']]);
                $results['rows'][]      = [
                    $financial['is_published'],
                    $financial['campaign_id'],
                    $financial['campaign_name'],
                    $financial['source_id'],
                    $financial['source_name'],
                    $financial['received'],
                    $financial['converted'],
                    $financial['revenue'],
                    $financial['cost'],
                    $financial['gm'],
                    $financial['margin'],
                    $financial['ecpm'],
                ];
                //
                // $results['summary']['gmTotal']        = $results['summary']['gmTotal'] + $financial['gm'];
                // $results['summary']['costTotal']      = $results['summary']['costTotal'] + $financial['cost'];
                // $results['summary']['revenueTotal']   = $results['summary']['revenueTotal'] + $financial['revenue'];
                // $results['summary']['ecpmTotal']      = $results['summary']['gmTotal'] / 1000;
                // $results['summary']['marginTotal']    = $results['summary']['revenueTotal'] ? ($results['summary']['gmTotal'] / $results['summary']['revenueTotal']) * 100 : 0;
                // $results['summary']['receivedTotal']  = $results['summary']['receivedTotal'] + $financial['received'];
                // $results['summary']['convertedTotal'] = $results['summary']['convertedTotal'] + $financial['converted'];
            }
        }

        return $results;
    }
}
