<?php
// Shared CSS for Module Batch wizard (add_module_batch.php & edit_module_batch.php)
?>
<style>
    /* ========== Step Indicator ========== */
    .step-indicator-wrapper {
        position: sticky;
        top: 0;
        z-index: 1000;
        margin-bottom: 32px;
        transition: background 0.3s ease, box-shadow 0.3s ease;
    }
    .step-indicator-wrapper.is-fixed {
        padding: 12px 20px;
        background: #f5f5f5;
        border-bottom: 1px solid #e0e0e0;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
    }
    .step-indicator-wrapper.is-fixed .step-indicator {
        max-width: 1200px;
        margin: 0 auto;
        border-radius: 12px;
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
        padding: 12px 16px;
    }
    .step-indicator-wrapper.is-fixed .step-label { display: none; }
    .step-indicator-wrapper.is-fixed .step-tag { display: none; }
    .step-indicator-wrapper.is-fixed .step { padding: 8px 12px; }
    .step-indicator-wrapper.is-fixed .step-number { width: 32px; height: 32px; font-size: 0.85rem; }
    .step-indicator-wrapper.is-fixed .step-connector { width: 40px; }
    .step-indicator-wrapper.is-fixed .current-step-label { display: flex; }
    .step-indicator-placeholder { display: none; }
    .step-indicator-placeholder.active { display: block; }
    .current-step-label {
        display: none;
        align-items: center;
        gap: 8px;
        margin-left: 16px;
        padding-left: 16px;
        border-left: 2px solid #e9ecef;
        font-weight: 600;
        color: #293E4C;
        font-size: 0.95rem;
    }
    .current-step-label .step-name { color: #488C9A; }
    .step-indicator {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 0;
        padding: 20px;
        background: #fff;
        border-radius: 16px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
        transition: all 0.3s ease;
    }
    .step {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 12px 20px;
        cursor: pointer;
        transition: all 0.3s ease;
        border-radius: 12px;
    }
    .step:hover { background: rgba(72, 140, 154, 0.06); }
    .step:hover .step-number { transform: scale(1.05); }
    .step-number {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        background: #e9ecef;
        color: #6c757d;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        font-size: 0.9rem;
        transition: all 0.3s ease;
        position: relative;
    }
    .step.current .step-number {
        background: linear-gradient(135deg, #488C9A 0%, #3a7a87 100%);
        color: #fff;
        box-shadow: 0 4px 12px rgba(72, 140, 154, 0.4);
        animation: pulse-ring 2s ease-in-out infinite;
    }
    .step.completed .step-number { background: #28a745; color: #fff; }
    .step.completed .step-number::after { content: '\2713'; }
    .step-title, .step-label { font-weight: 500; color: #6c757d; font-size: 0.95rem; transition: color 0.2s ease; }
    .step.current .step-title, .step.current .step-label { color: #293E4C; font-weight: 600; }
    .step.completed .step-title, .step.completed .step-label { color: #28a745; }
    .step-connector { width: 60px; height: 3px; background: #e9ecef; margin: 0 5px; border-radius: 2px; transition: background 0.3s ease; }
    .step-connector.completed { background: linear-gradient(90deg, #28a745, #34ce57); }
    .step-tag { font-size: 0.7rem; padding: 2px 8px; border-radius: 10px; margin-left: 8px; font-weight: 600; transition: all 0.3s ease; }
    .step-tag.required { background: #fff3cd; color: #856404; }
    .step-tag.optional { background: #e9ecef; color: #6c757d; }
    .step.current .step-tag.required { background: #488C9A; color: #fff; }
    .step.completed .step-tag { background: #d4edda; color: #155724; }
    @keyframes pulse-ring {
        0% { box-shadow: 0 4px 12px rgba(72, 140, 154, 0.4); }
        50% { box-shadow: 0 4px 20px rgba(72, 140, 154, 0.6); }
        100% { box-shadow: 0 4px 12px rgba(72, 140, 154, 0.4); }
    }

    /* ========== Accordion Sections ========== */
    .accordion-section {
        background: #fff;
        border-radius: 16px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
        margin-bottom: 20px;
        overflow: hidden;
        border: 1px solid #e9ecef;
    }
    .accordion-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 20px 24px;
        background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
        cursor: pointer;
        transition: all 0.3s ease;
        border-bottom: 1px solid transparent;
    }
    .accordion-header:hover { background: #f8f9fa; }
    .accordion-header.active { border-bottom: 1px solid #e9ecef; }
    .accordion-header h2 {
        margin: 0;
        font-size: 1.25rem;
        font-weight: 600;
        color: #293E4C;
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .accordion-header h2 .step-badge {
        background: linear-gradient(135deg, #488C9A 0%, #3a7a87 100%);
        color: #fff;
        width: 28px;
        height: 28px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.85rem;
    }
    .accordion-header h2 .step-badge.completed { background: #28a745; }
    .accordion-toggle { font-size: 1.5rem; color: #6c757d; transition: transform 0.3s ease; }
    .accordion-header.active .accordion-toggle { transform: rotate(180deg); }
    .accordion-content { padding: 0; max-height: 0; overflow: hidden; transition: max-height 0.4s ease, padding 0.3s ease; }
    .accordion-content.open { padding: 24px; max-height: 5000px; }
    .accordion-body { padding: 24px; }
    .section-description {
        color: #6c757d;
        margin-bottom: 24px;
        padding: 16px;
        background: #f8f9fa;
        border-radius: 12px;
        border-left: 4px solid #488C9A;
    }

    /* ========== Section Action Buttons ========== */
    .section-actions {
        display: flex;
        justify-content: flex-end;
        align-items: center;
        gap: 12px;
        margin-top: 24px;
        padding-top: 24px;
        border-top: 1px solid #e9ecef;
    }
    .section-actions .btn-back-step { margin-right: auto; }
    .section-actions-right {
        display: flex;
        gap: 12px;
        align-items: center;
    }
    .btn-continue {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 14px 28px;
        background: linear-gradient(135deg, #488C9A 0%, #3a7a87 100%);
        color: #fff;
        border: none;
        border-radius: 12px;
        font-size: 1rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
    }
    .btn-continue:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(72, 140, 154, 0.4); }
    .btn-back-step {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 14px 24px;
        background: #fff;
        color: #6c757d;
        border: 2px solid #e9ecef;
        border-radius: 12px;
        font-size: 1rem;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s ease;
    }
    .btn-back-step:hover { background: #f8f9fa; border-color: #dee2e6; }

    /* ========== Module Batch Form Fields ========== */
    .module-section { grid-column: 1 / -1; margin-top: 0; padding-top: 0; }
    .form-grid { display: grid; grid-template-columns: 1fr; gap: 24px; }
    .form-section { padding: 0; border: none; background: transparent; }
    .form-section h2 { font-size: 1.2rem; font-weight: 600; color: #1a1a1a; margin-bottom: 16px; padding-bottom: 10px; border-bottom: 2px solid #488C9A; }
    .input-group { margin-bottom: 16px; }
    .input-group label { display: block; font-weight: 500; color: #333; margin-bottom: 8px; font-size: 0.95rem; }
    .input-group input, .input-group select, .input-group textarea { width: 100%; padding: 12px 16px; border: 2px solid #e8e8e8; border-radius: 8px; font-size: 1rem; transition: all 0.2s ease; box-sizing: border-box; background: #fafafa; }
    .input-group input:focus, .input-group select:focus, .input-group textarea:focus { outline: none; border-color: #488C9A; background: #fff; box-shadow: 0 0 0 3px rgba(72,140,154,0.1); }

    /* Two-column layout for Manufacturer/Location and Wattage/Quantities */
    .mb-two-column-layout { display: grid; grid-template-columns: 1fr 1fr; gap: 32px; align-items: start; }
    .mb-left-column, .mb-right-column { min-width: 0; }
    .mb-right-column .input-group label { margin-bottom: 12px; }
    .mb-domestic-toggle { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; font-size: 0.9rem; color: #495057; }
    .mb-domestic-toggle input[type="checkbox"] { width: 16px; height: 16px; accent-color: #488C9A; }

    .wattage-container { margin: 0; }
    .wattage-entry { display: grid; grid-template-columns: 1fr 1fr auto; gap: 12px; align-items: end; padding: 12px; background: #f8f9fa; border-radius: 10px; margin-bottom: 10px; border: 1px solid #e9ecef; }
    .wattage-entry.has-domestic { grid-template-columns: 1fr 1fr 1fr auto; }
    .wattage-entry .input-group { margin-bottom: 0; }
    .wattage-entry .input-group label { font-size: 0.85rem; margin-bottom: 4px; }
    .wattage-entry .input-group input { padding: 10px 12px; font-size: 0.95rem; }
    .remove-btn { background: #dc3545; color: #fff; border: none; padding: 8px 12px; border-radius: 6px; cursor: pointer; font-weight: 500; font-size: 0.85rem; }
    .add-wattage-btn { background: #488C9A; color: #fff; border: none; padding: 8px 14px; border-radius: 6px; cursor: pointer; font-weight: 500; font-size: 0.9rem; margin-top: 8px; }

    /* Location Address Display */
    .location-address-display {
        margin-top: 8px;
        padding: 10px 14px;
        background: linear-gradient(135deg, #f0f8ff 0%, #e7f3ff 100%);
        border: 1px solid #b8daff;
        border-radius: 8px;
        font-size: 0.9rem;
        color: #0056b3;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .location-address-display .address-icon { font-size: 1rem; }

    /* ========== Inline Sections (replacing slide-out panels) ========== */
    .form-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 20px;
        margin-bottom: 20px;
    }
    .form-row.single { grid-template-columns: 1fr; }
    .form-group { display: flex; flex-direction: column; }
    .form-group label { font-weight: 500; color: #333; margin-bottom: 8px; font-size: 0.95rem; }
    .form-group label .required-star { color: #dc3545; margin-left: 4px; }
    .form-group label .optional-tag { color: #6c757d; font-weight: 400; font-size: 0.85rem; margin-left: 8px; }
    .form-group input, .form-group select, .form-group textarea {
        padding: 12px 16px;
        border: 2px solid #e9ecef;
        border-radius: 10px;
        font-size: 1rem;
        transition: all 0.2s ease;
        background: #fafafa;
    }
    .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
        outline: none;
        border-color: #488C9A;
        background: #fff;
        box-shadow: 0 0 0 3px rgba(72, 140, 154, 0.1);
    }
    .form-group input[readonly] { background: #f8f9fa; color: #6c757d; }
    .form-group textarea { min-height: 80px; resize: vertical; }
    .form-group .help-text { font-size: 0.85rem; color: #6c757d; margin-top: 6px; }
    .form-subsection-title {
        font-size: 0.95rem;
        font-weight: 600;
        color: #293E4C;
        margin: 24px 0 12px 0;
        padding-bottom: 8px;
        border-bottom: 2px solid #e9ecef;
    }
    .form-subsection-title:first-of-type { margin-top: 0; }

    /* ========== Milestone Section ========== */
    .milestone-row {
        padding: 12px;
        background: #f8f9fa;
        border-radius: 8px;
        margin-bottom: 10px;
        border: 1px solid #e9ecef;
    }
    .milestone-row-inner {
        display: flex;
        gap: 12px;
        align-items: flex-end;
    }
    .milestone-trigger-col { flex: 3; min-width: 0; }
    .milestone-pct-col { flex: 0 0 120px; }
    .milestone-remove-col { flex: 0 0 auto; }
    .milestone-label { font-size: 11px; color: #6c757d; text-transform: uppercase; letter-spacing: 0.3px; display: block; margin-bottom: 4px; }
    .milestone-select, .milestone-input {
        width: 100%;
        padding: 8px 10px;
        border: 1px solid #dee2e6;
        border-radius: 6px;
        font-size: 13px;
    }
    .milestone-input { width: 80px; }
    .milestone-remove-btn {
        background: #dc3545;
        color: white;
        border: none;
        padding: 8px 12px;
        border-radius: 6px;
        cursor: pointer;
        font-size: 14px;
    }
    .milestone-trigger-info {
        margin-top: 8px;
        font-size: 11px;
        color: #17a2b8;
        font-style: italic;
        min-height: 14px;
    }

    /* ========== File Upload Drop Zone ========== */
    .file-drop-zone {
        border: 2px dashed #6f42c1;
        border-radius: 12px;
        padding: 30px 20px;
        text-align: center;
        cursor: pointer;
        background: linear-gradient(135deg, #f8f5fc 0%, #ffffff 100%);
        transition: all 0.3s ease;
    }
    .file-drop-zone:hover, .file-drop-zone.dragover {
        border-color: #5a32a3;
        background: #e2d9f3;
    }

    /* ========== Accordion Header Layout ========== */
    .accordion-header-left {
        display: flex;
        align-items: center;
        gap: 16px;
        flex: 1;
    }
    .accordion-number {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        background: #e9ecef;
        color: #6c757d;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 0.9rem;
        flex-shrink: 0;
        transition: all 0.3s ease;
    }
    .accordion-section.active .accordion-number {
        background: linear-gradient(135deg, #488C9A 0%, #3a7a87 100%);
        color: #fff;
    }
    .accordion-section.completed .accordion-number {
        background: #28a745;
        color: #fff;
    }
    .accordion-section.completed .accordion-number::after {
        content: '\2713';
        font-size: 0.75rem;
    }
    .accordion-title {
        font-size: 1.15rem;
        font-weight: 600;
        color: #293E4C;
        line-height: 1.3;
    }
    .accordion-header .section-description {
        padding: 0;
        background: none;
        border: none;
        border-left: none;
        border-radius: 0;
        margin: 2px 0 0 0;
        font-size: 0.85rem;
        color: #6c757d;
    }
    .accordion-tag {
        font-size: 0.75rem;
        padding: 4px 12px;
        border-radius: 20px;
        text-transform: uppercase;
        font-weight: 600;
        letter-spacing: 0.3px;
        white-space: nowrap;
        transition: all 0.3s ease;
    }
    .required-tag {
        background: #fff3cd;
        color: #856404;
    }
    .recommended-tag {
        background: #e2e3f1;
        color: #5a5d8e;
    }
    .accordion-tag.optional-tag {
        background: #e9ecef;
        color: #6c757d;
    }
    .accordion-section.active .required-tag {
        background: #488C9A;
        color: #fff;
    }
    .accordion-section.active .recommended-tag {
        background: #488C9A;
        color: #fff;
    }
    .accordion-section.completed .accordion-tag {
        background: #d4edda;
        color: #155724;
    }
    .accordion-chevron {
        color: #6c757d;
        font-size: 1rem;
        transition: transform 0.3s ease;
        margin-left: 12px;
    }
    .accordion-section.active .accordion-chevron {
        transform: rotate(180deg);
    }

    /* ========== Submit Button ========== */
    .btn-submit {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 16px 40px;
        background: linear-gradient(135deg, #28a745 0%, #20963b 100%);
        color: #fff;
        border: none;
        border-radius: 12px;
        font-size: 1.1rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        min-width: 200px;
        justify-content: center;
    }
    .btn-submit:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4);
    }

    /* ========== Inline PO Date in Milestones ========== */
    .milestone-po-date-row {
        display: none;
        margin-top: 8px;
        padding: 10px 12px;
        background: linear-gradient(135deg, #e8f4f8 0%, #f0f8ff 100%);
        border: 1px solid #b8daff;
        border-radius: 6px;
    }
    .milestone-po-date-row.visible {
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .milestone-po-date-row label {
        font-size: 12px;
        font-weight: 600;
        color: #488C9A;
        white-space: nowrap;
    }
    .milestone-po-date-row input[type="date"] {
        padding: 6px 10px;
        border: 1px solid #dee2e6;
        border-radius: 6px;
        font-size: 13px;
        max-width: 200px;
    }
    .milestone-po-date-row input[type="date"]:focus {
        outline: none;
        border-color: #488C9A;
        box-shadow: 0 0 0 2px rgba(72, 140, 154, 0.15);
    }

    /* ========== Responsive ========== */
    @media (max-width: 900px) {
        .form-grid { grid-template-columns: 1fr; }
        .mb-two-column-layout { grid-template-columns: 1fr; gap: 24px; }
        .wattage-entry { grid-template-columns: 1fr; gap: 10px; }
        .form-row { grid-template-columns: 1fr; }
    }
    @media (max-width: 768px) {
        .step-indicator { flex-wrap: wrap; padding: 12px; }
        .step { padding: 8px 12px; }
        .step-connector { width: 30px; }
        .section-actions { flex-direction: column; gap: 12px; }
        .section-actions .btn-back-step { margin-right: 0; width: 100%; justify-content: center; }
        .section-actions .btn-continue { width: 100%; justify-content: center; }
        .section-actions .btn-submit { width: 100%; justify-content: center; }
        .milestone-po-date-row.visible { flex-direction: column; align-items: flex-start; }
        .milestone-po-date-row input[type="date"] { max-width: 100%; width: 100%; }
    }
</style>
