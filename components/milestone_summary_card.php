<?php
/**
 * Milestone Summary Card Component
 *
 * Displays a summary of module payment milestone progress.
 *
 * Usage:
 * $milestone_status = get_milestone_completion_status($project_id, $conn);
 * include 'components/milestone_summary_card.php';
 *
 * Required variables:
 * - $milestone_status: array from get_milestone_completion_status()
 *
 * Optional variables:
 * - $card_title: string (default: 'Module Payment Progress')
 * - $show_batches: bool (default: false) - Show breakdown by module batch
 * - $compact: bool (default: false) - Use compact layout
 */

// Ensure we have the required data
if (!isset($milestone_status) || !is_array($milestone_status)) {
    return;
}

// Check if there's any milestone data to show
if ($milestone_status['total_contract_value'] <= 0) {
    return; // No milestones configured, don't show the card
}

// Set defaults
$card_title = $card_title ?? 'Module Payment Progress';
$show_batches = $show_batches ?? false;
$compact = $compact ?? false;

// Calculate values
$total_contract = $milestone_status['total_contract_value'];
$total_triggered = $milestone_status['total_triggered'];
$total_remaining = $milestone_status['total_remaining'];
$completion_percent = $milestone_status['completion_percent'];

// Determine progress bar color based on completion
$progress_color = '#17a2b8'; // Default teal
if ($completion_percent >= 100) {
    $progress_color = '#28a745'; // Green when complete
} elseif ($completion_percent >= 75) {
    $progress_color = '#17a2b8'; // Teal
} elseif ($completion_percent >= 50) {
    $progress_color = '#ffc107'; // Yellow
}
?>

<div class="milestone-summary-card <?php echo $compact ? 'compact' : ''; ?>" style="
    background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
    border: 1px solid #e9ecef;
    border-radius: 12px;
    padding: <?php echo $compact ? '16px' : '20px 24px'; ?>;
    <?php if (!$compact): ?>box-shadow: 0 2px 8px rgba(0,0,0,0.04);<?php endif; ?>
">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: <?php echo $compact ? '12px' : '16px'; ?>;">
        <h4 style="margin: 0; font-size: <?php echo $compact ? '14px' : '16px'; ?>; font-weight: 600; color: #293E4C; display: flex; align-items: center; gap: 8px;">
            <span style="color: #17a2b8;">💰</span>
            <?php echo htmlspecialchars($card_title); ?>
        </h4>
        <span style="font-size: <?php echo $compact ? '18px' : '22px'; ?>; font-weight: 700; color: <?php echo $progress_color; ?>;">
            <?php echo number_format($completion_percent, 0); ?>%
        </span>
    </div>

    <!-- Progress Bar -->
    <div style="
        background: #e9ecef;
        border-radius: 8px;
        height: <?php echo $compact ? '8px' : '12px'; ?>;
        overflow: hidden;
        margin-bottom: <?php echo $compact ? '12px' : '16px'; ?>;
    ">
        <div style="
            background: linear-gradient(90deg, <?php echo $progress_color; ?> 0%, <?php echo $progress_color; ?>dd 100%);
            height: 100%;
            width: <?php echo min($completion_percent, 100); ?>%;
            border-radius: 8px;
            transition: width 0.5s ease;
        "></div>
    </div>

    <!-- Stats Grid -->
    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: <?php echo $compact ? '12px' : '16px'; ?>;">
        <div style="text-align: center;">
            <div style="font-size: 11px; color: #6c757d; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">
                Contract Value
            </div>
            <div style="font-size: <?php echo $compact ? '16px' : '18px'; ?>; font-weight: 600; color: #293E4C;">
                $<?php echo number_format($total_contract, 0); ?>
            </div>
        </div>
        <div style="text-align: center; border-left: 1px solid #e9ecef; border-right: 1px solid #e9ecef;">
            <div style="font-size: 11px; color: #6c757d; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">
                Triggered
            </div>
            <div style="font-size: <?php echo $compact ? '16px' : '18px'; ?>; font-weight: 600; color: #28a745;">
                $<?php echo number_format($total_triggered, 0); ?>
            </div>
        </div>
        <div style="text-align: center;">
            <div style="font-size: 11px; color: #6c757d; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">
                Remaining
            </div>
            <div style="font-size: <?php echo $compact ? '16px' : '18px'; ?>; font-weight: 600; color: #6c757d;">
                $<?php echo number_format($total_remaining, 0); ?>
            </div>
        </div>
    </div>

    <?php if ($show_batches && !empty($milestone_status['batches'])): ?>
    <!-- Batch Breakdown -->
    <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #e9ecef;">
        <div style="font-size: 12px; color: #6c757d; font-weight: 500; margin-bottom: 8px;">By Module Batch:</div>
        <?php foreach ($milestone_status['batches'] as $batch): ?>
        <div style="display: flex; justify-content: space-between; align-items: center; padding: 6px 0; font-size: 13px;">
            <span style="color: #495057;"><?php echo htmlspecialchars($batch['vendor_name'] ?? 'Unknown'); ?></span>
            <span style="color: #293E4C; font-weight: 500;">$<?php echo number_format($batch['batch_value'], 0); ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
