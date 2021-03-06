<script src="/plugins/MauticContactLedgerBundle/Assets/js/campaign-revenue.js"></script>
<div class="pa-md">
    <div class="row">
        <div class="col-sm-12">
            <div class="panel">
                <div class="panel-body box-layout">
                    <div class="col-md-3 va-m">
                        <h5 class="text-white dark-md fw-sb mb-xs">
                            <span class="fa fa-line-chart"></span>
                            <?php echo $view['translator']->trans('mautic.contactledger.campaignrevenue'); ?>
                        </h5>
                    </div>
                    <div class="col-md-9 va-m">
                        <?php echo $view->render(
                            'MauticCoreBundle:Helper:graph_dateselect.html.php',
                            ['dateRangeForm' => $dateRangeForm, 'class' => 'pull-right']
                        ); ?>
                    </div>
                </div>
                <div class="pt-0 pl-15 pb-10 pr-15">
                    <?php echo $view->render(
                        'MauticCoreBundle:Helper:chart.html.php',
                        ['chartData' => $campaignRevenueChartData, 'chartType' => 'line', 'chartHeight' => 300]
                    ); ?>
                </div>
                <div class="pt-0 pl-15 pb-10 pr-15">
                    <table id="campaign-revenue-table" class="table table-striped table-bordered no-footer display" style="width:100%">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Cost</th>
                                <th>Revenue</th>
                                <th>Profit</th>
                            </tr>
                        </thead>
                        <tfoot>
                            <tr class='pageTotal' style='font-weight: 600; background: #fafafa;'>
                                <td>Page Total</td><td></td><td></td><td></td>
                            </tr>
                            <tr class='grandTotal' style='font-weight: 600; background: #fafafa;'>
                                <td>Grand Total</td><td></td><td></td><td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
// TODO: There are better ways to do this but I haven't gotten them to work
$params  = '?action=plugin:MauticContactLedger:datatables';
$params .= '&which=campaign-ledger';
$params .= '&campaignId='.$campaign->getId();
$params .= '&date_to='.urlencode($dateRangeForm['date_to']->vars['value']);
$params .= '&date_from='.urlencode($dateRangeForm['date_from']->vars['value']);
?><script>
    Mautic.loadCampaignRevenueWidget('<?php echo $params; ?>')
</script>
