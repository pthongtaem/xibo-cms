<?php
/*
 * Xibo - Digital Signage - http://www.xibo.org.uk
 * Copyright (C) 2006-2015 Daniel Garner
 *
 * This file (StatusDashboard.php) is part of Xibo.
 *
 * Xibo is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version. 
 *
 * Xibo is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Foobar.  If not, see <http://www.gnu.org/licenses/>.
 */
namespace Xibo\Controller;
use Exception;
use PicoFeed\PicoFeedException;
use PicoFeed\Reader\Reader;
use Stash\Interfaces\PoolInterface;
use Xibo\Factory\DisplayFactory;
use Xibo\Factory\DisplayGroupFactory;
use Xibo\Factory\MediaFactory;
use Xibo\Factory\UserFactory;
use Xibo\Helper\ByteFormatter;
use Xibo\Service\ConfigServiceInterface;
use Xibo\Service\DateServiceInterface;
use Xibo\Service\LogServiceInterface;
use Xibo\Service\SanitizerServiceInterface;
use Xibo\Storage\StorageServiceInterface;

/**
 * Class StatusDashboard
 * @package Xibo\Controller
 */
class StatusDashboard extends Base
{
    /**
     * @var StorageServiceInterface
     */
    private $store;

    /**
     * @var PoolInterface
     */
    private $pool;

    /**
     * @var UserFactory
     */
    private $userFactory;

    /**
     * @var DisplayFactory
     */
    private $displayFactory;

    /**
     * @var DisplayGroupFactory
     */
    private $displayGroupFactory;

    /**
     * @var MediaFactory
     */
    private $mediaFactory;

    /**
     * Set common dependencies.
     * @param LogServiceInterface $log
     * @param SanitizerServiceInterface $sanitizerService
     * @param \Xibo\Helper\ApplicationState $state
     * @param \Xibo\Entity\User $user
     * @param \Xibo\Service\HelpServiceInterface $help
     * @param DateServiceInterface $date
     * @param ConfigServiceInterface $config
     * @param StorageServiceInterface $store
     * @param PoolInterface $pool
     * @param UserFactory $userFactory
     * @param DisplayFactory $displayFactory
     * @param DisplayGroupFactory $displayGroupFactory
     * @param MediaFactory $mediaFactory
     */
    public function __construct($log, $sanitizerService, $state, $user, $help, $date, $config, $store, $pool, $userFactory, $displayFactory, $displayGroupFactory, $mediaFactory)
    {
        $this->setCommonDependencies($log, $sanitizerService, $state, $user, $help, $date, $config);

        $this->store = $store;
        $this->pool = $pool;
        $this->userFactory = $userFactory;
        $this->displayFactory = $displayFactory;
        $this->displayGroupFactory = $displayGroupFactory;
        $this->mediaFactory = $mediaFactory;
    }

    /**
     * View
     */
    function displayPage()
    {
        $data = [];

        // Set up some suffixes
        $suffixes = array('B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB');

        try {
            // Displays this user has access to
            $displays = $this->displayFactory->query(['display']);
            $displayIds = array_map(function($element) {
                return $element->displayId;
            }, $displays);
            $displayIds[] = -1;

            // Get some data for a bandwidth chart
            $dbh = $this->store->getConnection();

            $sql = '
              SELECT MAX(FROM_UNIXTIME(month)) AS month,
                  IFNULL(SUM(Size), 0) AS size
                FROM `bandwidth`
               WHERE month > :month AND displayId IN (' . implode(',', $displayIds) . ')
              GROUP BY MONTH(FROM_UNIXTIME(month)) ORDER BY MIN(month);
              ';
            $params = array('month' => time() - (86400 * 365));


            $results = $this->store->select($sql, $params);

            // Monthly bandwidth - optionally tested against limits
            $xmdsLimit = $this->getConfig()->GetSetting('MONTHLY_XMDS_TRANSFER_LIMIT_KB');

            $maxSize = 0;
            foreach ($results as $row) {
                $maxSize = ($row['size'] > $maxSize) ? $row['size'] : $maxSize;
            }

            // Decide what our units are going to be, based on the size
            $base = ($maxSize == 0) ? 0 : floor(log($maxSize) / log(1024));

            if ($xmdsLimit > 0) {
                // Convert to appropriate size (xmds limit is in KB)
                $xmdsLimit = ($xmdsLimit * 1024) / (pow(1024, $base));
                $data['xmdsLimit'] = $xmdsLimit . ' ' . $suffixes[$base];
            }

            $output = array();

            foreach ($results as $row) {
                $size = ((double)$row['size']) / (pow(1024, $base));
                $remaining = $xmdsLimit - $size;
                $output[] = array(
                    'label' => $this->getDate()->getLocalDate($this->getSanitizer()->getDate('month', $row), 'F'),
                    'value' => round($size, 2),
                    'limit' => round($remaining, 2)
                );
            }

            // What if we are empty?
            if (count($output) == 0) {
                $output[] = array(
                    'label' => $this->getDate()->getLocalDate(null, 'F'),
                    'value' => 0,
                    'limit' => 0
                );
            }

            // Set the data
            $data['xmdsLimitSet'] = ($xmdsLimit > 0);
            $data['bandwidthSuffix'] = $suffixes[$base];
            $data['bandwidthWidget'] = json_encode($output);

            // We would also like a library usage pie chart!
            if ($this->getUser()->libraryQuota != 0) {
                $libraryLimit = $this->getUser()->libraryQuota * 1024;
            }
            else {
                $libraryLimit = $this->getConfig()->GetSetting('LIBRARY_SIZE_LIMIT_KB') * 1024;
            }

            // Library Size in Bytes
            $params = [];
            $sql = 'SELECT IFNULL(SUM(FileSize), 0) AS SumSize, type FROM `media` WHERE 1 = 1 ';
            $this->mediaFactory->viewPermissionSql('Xibo\Entity\Media', $sql, $params, '`media`.mediaId', '`media`.userId');
            $sql .= ' GROUP BY type ';

            $sth = $dbh->prepare($sql);
            $sth->execute($params);

            $results = $sth->fetchAll();

            // Do we base the units on the maximum size or the library limit
            $maxSize = 0;
            if ($libraryLimit > 0) {
                $maxSize = $libraryLimit;
            } else {
                // Find the maximum sized chunk of the items in the library
                foreach ($results as $library) {
                    $maxSize = ($library['SumSize'] > $maxSize) ? $library['SumSize'] : $maxSize;
                }
            }

            // Decide what our units are going to be, based on the size
            $base = ($maxSize == 0) ? 0 : floor(log($maxSize) / log(1024));

            $output = [];
            $totalSize = 0;
            foreach ($results as $library) {
                $output[] = array(
                    'value' => round((double)$library['SumSize'] / (pow(1024, $base)), 2),
                    'label' => ucfirst($library['type'])
                );
                $totalSize = $totalSize + $library['SumSize'];
            }

            // Do we need to add the library remaining?
            if ($libraryLimit > 0) {
                $remaining = round(($libraryLimit - $totalSize) / (pow(1024, $base)), 2);
                $output[] = array(
                    'value' => $remaining,
                    'label' => __('Free')
                );
            }

            // What if we are empty?
            if (count($output) == 0) {
                $output[] = array(
                    'label' => __('Empty'),
                    'value' => 0
                );
            }

            $data['libraryLimitSet'] = $libraryLimit;
            $data['libraryLimit'] = (round((double)$libraryLimit / (pow(1024, $base)), 2)) . ' ' . $suffixes[$base];
            $data['librarySize'] = ByteFormatter::format($totalSize, 1);
            $data['librarySuffix'] = $suffixes[$base];
            $data['libraryWidget'] = json_encode($output);

            // Also a display widget
            $data['displays'] = $displays;

            // Get a count of users
            $data['countUsers'] = count($this->userFactory->query());

            // Get a count of active layouts, only for display groups we have permission for
            $displayGroups = $this->displayGroupFactory->query(null, ['isDisplaySpecific' => -1]);
            $displayGroupIds = array_map(function($element) {
                return $element->displayGroupId;
            }, $displayGroups);
            // Add an empty one
            $displayGroupIds[] = -1;

            $sql = '
              SELECT IFNULL(COUNT(*), 0) AS count_scheduled 
                FROM `schedule` 
               WHERE (
                  :now BETWEEN FromDT AND ToDT
                  OR `schedule`.recurrence_range >= :now 
                  OR (
                    IFNULL(`schedule`.recurrence_range, 0) = 0 AND IFNULL(`schedule`.recurrence_type, \'\') <> \'\' 
                  )
                )
                AND eventId IN (
                  SELECT eventId 
                    FROM `lkscheduledisplaygroup` 
                   WHERE displayGroupId IN (' . implode(',', $displayGroupIds) . ')
                )
            ';
            $params = array('now' => time());

            $sth = $dbh->prepare($sql);
            $sth->execute($params);

            $data['nowShowing'] = $sth->fetchColumn(0);

            // Latest news
            if ($this->getConfig()->GetSetting('DASHBOARD_LATEST_NEWS_ENABLED') == 1 && !empty($this->getConfig()->GetSetting('LATEST_NEWS_URL'))) {
                // Make sure we have the cache location configured
                Library::ensureLibraryExists($this->getConfig()->GetSetting('LIBRARY_LOCATION'));

                try {
                    $feedUrl = $this->getConfig()->GetSetting('LATEST_NEWS_URL');
                    $cache = $this->pool->getItem('rss/' . md5($feedUrl));

                    $latestNews = $cache->get();

                    // Check the cache
                    if ($cache->isMiss()) {

                        // Get the feed
                        $reader = new Reader($this->getConfig()->getPicoFeedProxy($feedUrl));
                        $resource = $reader->download($feedUrl);

                        // Get the feed parser
                        $parser = $reader->getParser($resource->getUrl(), $resource->getContent(), $resource->getEncoding());

                        // Get a feed object
                        $feed = $parser->execute();

                        // Parse the items in the feed
                        $latestNews = [];

                        foreach ($feed->getItems() as $item) {
                            /* @var \PicoFeed\Parser\Item $item */
                            $latestNews[] = array(
                                'title' => $item->getTitle(),
                                'description' => $item->getContent(),
                                'link' => $item->getUrl()
                            );
                        }

                        // Store in the cache for 1 day
                        $cache->set($latestNews);
                        $cache->expiresAfter(86400);

                        $this->pool->saveDeferred($cache);
                    }

                    $data['latestNews'] = $latestNews;
                }
                catch (PicoFeedException $e) {
                    $this->getLog()->error('Unable to get feed: %s', $e->getMessage());
                    $this->getLog()->debug($e->getTraceAsString());

                    $data['latestNews'] = array(array('title' => __('Latest news not available.'), 'description' => '', 'link' => ''));
                }
            }
            else {
                $data['latestNews'] = array(array('title' => __('Latest news not enabled.'), 'description' => '', 'link' => ''));
            }
        }
        catch (Exception $e) {

            $this->getLog()->error($e->getMessage());
            $this->getLog()->debug($e->getTraceAsString());

            // Show the error in place of the bandwidth chart
            $data['widget-error'] = 'Unable to get widget details';
        }

        // Do we have an embedded widget?
        $data['embedded-widget'] = html_entity_decode($this->getConfig()->GetSetting('EMBEDDED_STATUS_WIDGET'));

        // Render the Theme and output
        $this->getState()->template = 'dashboard-status-page';
        $this->getState()->setData($data);
    }
}
