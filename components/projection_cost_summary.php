<?php
/**
 * Projection Cost Summary Component
 * Shows cost breakdown and milestone payment schedule
 *
 * Required variables:
 * - $cost_summary: Array with cost breakdown
 * - $milestone_schedule: Array with milestone payment info
 * - $allocated_modules: Array of allocated modules (for milestone details)
 */

$module_value = $cost_summary['module_contract_value'] ?? 0;
$freight_cost = $cost_summary['total_freight_cost'] ?? 0;
$warehouse_cost = $cost_summary['total_warehousing_cost'] ?? 0;
$customs_cost = $cost_summary['total_customs_cost'] ?? 0;
$other_cost = $cost_summary['total_other_cost'] ?? 0;
$show_customs_cost = $customs_cost > 0;
$logistics_total = $freight_cost + $warehouse_cost + $customs_cost + $other_cost;
$grand_total = $cost_summary['grand_total'] ?? ($module_value + $logistics_total);

// Calculate milestone payments from allocated modules
$milestone_payments = [];
$total_milestone_amount = 0;

if (!empty($allocated_modules)) {
    foreach ($allocated_modules as $alloc) {
        $contract_val = $alloc['contract_value'] ?? 0;
        if (!empty($alloc['milestones'])) {
            foreach ($alloc['milestones'] as $ms) {
                $amount = $contract_val * ($ms['percentage'] / 100);
                $trigger = $ms['trigger_event'];

                if (!isset($milestone_payments[$trigger])) {
                    $milestone_payments[$trigger] = [
                        'name' => $ms['milestone_name'],
                        'trigger' => $trigger,
                        'amount' => 0,
                        'percentage' => $ms['percentage']
                    ];
                }
                $milestone_payments[$trigger]['amount'] += $amount;
                $total_milestone_amount += $amount;
            }
        } elseif ($contract_val > 0) {
            // Default: 100% upon project delivery when no milestones configured
            $trigger = 'project_delivery';
            if (!isset($milestone_payments[$trigger])) {
                $milestone_payments[$trigger] = [
                    'name' => 'Project Delivery',
                    'trigger' => $trigger,
                    'amount' => 0,
                    'percentage' => 100,
                    'is_default' => true
                ];
            }
            $milestone_payments[$trigger]['amount'] += $contract_val;
            $total_milestone_amount += $contract_val;
        }
    }
}

// Default milestone order
$milestone_order = ['po_execution', 'shipping', 'customs_cleared', 'project_delivery'];
?>

<div class="cost-summary-card">
    <div class="card-header">
        <div class="card-title">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="12" y1="1" x2="12" y2="23"/>
                <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
            </svg>
            Cost Summary
        </div>
    </div>

    <div class="cost-breakdown">
        <div class="cost-columns">
            <!-- Projected Costs -->
            <div class="cost-column">
                <h4 class="column-title">Projected Costs</h4>

                <div class="cost-item major">
                    <span class="cost-label">Module Contract Value</span>
                    <span class="cost-value">$<?php echo number_format($module_value, 2); ?></span>
                </div>

                <div class="cost-divider"></div>

                <div class="cost-item">
                    <span class="cost-label">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="1" y="3" width="15" height="13"/>
                            <polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/>
                            <circle cx="5.5" cy="18.5" r="2.5"/>
                            <circle cx="18.5" cy="18.5" r="2.5"/>
                        </svg>
                        Freight Costs
                    </span>
                    <span class="cost-value" id="costSummaryFreight">$<?php echo number_format($freight_cost, 2); ?></span>
                </div>

                <div class="cost-item">
                    <span class="cost-label">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="2" y="7" width="20" height="14" rx="2"/>
                            <path d="M12 7V4"/>
                            <path d="M12 4H8"/>
                        </svg>
                        Warehousing
                    </span>
                    <span class="cost-value" id="costSummaryWarehousing">$<?php echo number_format($warehouse_cost, 2); ?></span>
                </div>

                <?php if ($show_customs_cost): ?>
                <div class="cost-item">
                    <span class="cost-label">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                            <polyline points="14 2 14 8 20 8"/>
                            <line x1="16" y1="13" x2="8" y2="13"/>
                            <line x1="16" y1="17" x2="8" y2="17"/>
                        </svg>
                        Customs/Duties
                    </span>
                    <span class="cost-value">$<?php echo number_format($customs_cost, 2); ?></span>
                </div>
                <?php endif; ?>

                <?php if ($other_cost > 0): ?>
                <div class="cost-item">
                    <span class="cost-label">Other Costs</span>
                    <span class="cost-value">$<?php echo number_format($other_cost, 2); ?></span>
                </div>
                <?php endif; ?>

                <div class="cost-divider"></div>

                <div class="cost-item subtotal">
                    <span class="cost-label">Total Logistics</span>
                    <span class="cost-value" id="costSummaryLogistics">$<?php echo number_format($logistics_total, 2); ?></span>
                </div>

                <div class="cost-item grand-total">
                    <span class="cost-label">GRAND TOTAL</span>
                    <span class="cost-value" id="costSummaryGrandTotal">$<?php echo number_format($grand_total, 2); ?></span>
                </div>
            </div>

            <!-- Milestone Payments -->
            <div class="cost-column">
                <h4 class="column-title">Milestone Payments</h4>

                <?php if (!empty($milestone_payments)): ?>
                    <?php foreach ($milestone_order as $trigger): ?>
                        <?php if (isset($milestone_payments[$trigger])): ?>
                            <?php $ms = $milestone_payments[$trigger]; ?>
                            <div class="cost-item milestone-item">
                                <span class="cost-label">
                                    <span class="milestone-dot milestone-<?php echo $trigger; ?>"></span>
                                    <?php echo htmlspecialchars($ms['name']); ?>
                                    <span class="milestone-pct">(<?php echo $ms['percentage']; ?>%)</span>
                                    <?php if (!empty($ms['is_default'])): ?>
                                    <span style="font-size:0.75em;font-weight:600;background:rgba(108,117,125,0.1);color:#6c757d;padding:1px 8px;border-radius:10px;margin-left:6px;">Default</span>
                                    <?php endif; ?>
                                </span>
                                <span class="cost-value">$<?php echo number_format($ms['amount'], 2); ?></span>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>

                    <div class="cost-divider"></div>

                    <div class="cost-item subtotal">
                        <span class="cost-label">Total Milestones</span>
                        <span class="cost-value">$<?php echo number_format($total_milestone_amount, 2); ?></span>
                    </div>

                    <?php if (abs($total_milestone_amount - $module_value) > 0.01): ?>
                        <div class="milestone-warning">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"/>
                                <line x1="12" y1="8" x2="12" y2="12"/>
                                <line x1="12" y1="16" x2="12.01" y2="16"/>
                            </svg>
                            Milestone total doesn't match contract value
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="no-milestones">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#ccc" stroke-width="1.5">
                            <circle cx="12" cy="12" r="10"/>
                            <line x1="12" y1="8" x2="12" y2="12"/>
                            <line x1="12" y1="16" x2="12.01" y2="16"/>
                        </svg>
                        <p>No milestones configured</p>
                        <small>Add milestones to module batches to see payment schedule</small>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Cost Breakdown Chart -->
    <div class="cost-chart-section">
        <h4 class="chart-title">Cost Distribution</h4>
        <div class="cost-bar">
            <?php
            $module_pct = $grand_total > 0 ? ($module_value / $grand_total) * 100 : 0;
            $freight_pct = $grand_total > 0 ? ($freight_cost / $grand_total) * 100 : 0;
            $warehouse_pct = $grand_total > 0 ? ($warehouse_cost / $grand_total) * 100 : 0;
            $customs_pct = $grand_total > 0 ? ($customs_cost / $grand_total) * 100 : 0;
            $other_pct = $grand_total > 0 ? ($other_cost / $grand_total) * 100 : 0;
            ?>
            <div class="cost-bar-segment module-segment" id="costBarModule" style="width: <?php echo $module_pct; ?>%;" title="Modules: <?php echo round($module_pct, 1); ?>%"></div>
            <div class="cost-bar-segment freight-segment" id="costBarFreight" style="width: <?php echo $freight_pct; ?>%;" title="Freight: <?php echo round($freight_pct, 1); ?>%"></div>
            <div class="cost-bar-segment warehouse-segment" id="costBarWarehouse" style="width: <?php echo $warehouse_pct; ?>%;" title="Warehousing: <?php echo round($warehouse_pct, 1); ?>%"></div>
            <?php if ($show_customs_cost): ?>
            <div class="cost-bar-segment customs-segment" style="width: <?php echo $customs_pct; ?>%;" title="Customs: <?php echo round($customs_pct, 1); ?>%"></div>
            <?php endif; ?>
            <?php if ($other_pct > 0): ?>
            <div class="cost-bar-segment other-segment" style="width: <?php echo $other_pct; ?>%;" title="Other: <?php echo round($other_pct, 1); ?>%"></div>
            <?php endif; ?>
        </div>
        <div class="cost-legend">
            <div class="legend-item">
                <span class="legend-color module-color"></span>
                <span id="costLegendModule">Modules (<?php echo round($module_pct, 1); ?>%)</span>
            </div>
            <div class="legend-item">
                <span class="legend-color freight-color"></span>
                <span id="costLegendFreight">Freight (<?php echo round($freight_pct, 1); ?>%)</span>
            </div>
            <div class="legend-item">
                <span class="legend-color warehouse-color"></span>
                <span id="costLegendWarehouse">Warehousing (<?php echo round($warehouse_pct, 1); ?>%)</span>
            </div>
            <?php if ($show_customs_cost): ?>
            <div class="legend-item">
                <span class="legend-color customs-color"></span>
                <span>Customs (<?php echo round($customs_pct, 1); ?>%)</span>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.cost-summary-card {
    background: white;
    border-radius: 16px;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.04);
    border: 1px solid rgba(72, 140, 154, 0.1);
    margin-bottom: 24px;
    overflow: hidden;
}

.card-header {
    padding: 20px 24px;
    border-bottom: 1px solid #e9ecef;
}

.card-title {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 1.1em;
    font-weight: 600;
    color: #293E4C;
}

.card-title svg {
    color: #488C9A;
}

.cost-breakdown {
    padding: 24px;
}

.cost-columns {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 32px;
}

.column-title {
    font-size: 0.95em;
    font-weight: 700;
    color: #293E4C;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin: 0 0 16px 0;
    padding-bottom: 12px;
    border-bottom: 2px solid #e9ecef;
}

.cost-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 0;
}

.cost-label {
    display: flex;
    align-items: center;
    gap: 8px;
    color: #495057;
    font-size: 0.95em;
}

.cost-label svg {
    color: #6c757d;
}

.cost-value {
    font-weight: 600;
    color: #293E4C;
}

.cost-item.major .cost-value {
    font-size: 1.2em;
    color: #488C9A;
}

.cost-divider {
    height: 1px;
    background: #e9ecef;
    margin: 12px 0;
}

.cost-item.subtotal {
    font-weight: 600;
}

.cost-item.subtotal .cost-value {
    color: #488C9A;
}

.cost-item.grand-total {
    background: linear-gradient(135deg, rgba(72, 140, 154, 0.1) 0%, rgba(58, 110, 127, 0.1) 100%);
    margin: 12px -16px -12px;
    padding: 16px;
    border-radius: 0 0 12px 12px;
}

.cost-item.grand-total .cost-label {
    font-weight: 700;
    color: #293E4C;
    font-size: 1em;
}

.cost-item.grand-total .cost-value {
    font-size: 1.3em;
    font-weight: 700;
    color: #488C9A;
}

/* Milestone styles */
.milestone-item {
    padding: 8px 0;
}

.milestone-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    display: inline-block;
}

.milestone-po_execution { background: #28a745; }
.milestone-shipping { background: #17a2b8; }
.milestone-customs_cleared { background: #ffc107; }
.milestone-project_delivery { background: #dc3545; }

.milestone-pct {
    font-size: 0.85em;
    color: #6c757d;
}

.milestone-warning {
    display: flex;
    align-items: center;
    gap: 8px;
    background: #fff3cd;
    color: #856404;
    padding: 10px 14px;
    border-radius: 8px;
    font-size: 0.85em;
    margin-top: 12px;
}

.no-milestones {
    text-align: center;
    padding: 24px;
    color: #6c757d;
}

.no-milestones svg {
    margin-bottom: 8px;
}

.no-milestones p {
    margin: 0 0 4px 0;
}

.no-milestones small {
    font-size: 0.85em;
}

/* Cost Chart */
.cost-chart-section {
    padding: 20px 24px;
    border-top: 1px solid #e9ecef;
    background: #f8f9fa;
}

.chart-title {
    font-size: 0.9em;
    font-weight: 600;
    color: #293E4C;
    margin: 0 0 12px 0;
}

.cost-bar {
    display: flex;
    height: 24px;
    border-radius: 12px;
    overflow: hidden;
    background: #e9ecef;
}

.cost-bar-segment {
    height: 100%;
    transition: width 0.5s ease;
}

.module-segment { background: linear-gradient(90deg, #488C9A, #3A6E7F); }
.freight-segment { background: linear-gradient(90deg, #17a2b8, #138496); }
.warehouse-segment { background: linear-gradient(90deg, #ffc107, #e0a800); }
.customs-segment { background: linear-gradient(90deg, #6c757d, #5a6268); }
.other-segment { background: linear-gradient(90deg, #adb5bd, #909599); }

.cost-legend {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    margin-top: 12px;
}

.legend-item {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 0.85em;
    color: #495057;
}

.legend-color {
    width: 12px;
    height: 12px;
    border-radius: 3px;
}

.module-color { background: #488C9A; }
.freight-color { background: #17a2b8; }
.warehouse-color { background: #ffc107; }
.customs-color { background: #6c757d; }

@media (max-width: 768px) {
    .cost-columns {
        grid-template-columns: 1fr;
    }

    .cost-legend {
        justify-content: center;
    }
}
</style>
