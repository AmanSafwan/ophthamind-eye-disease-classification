<?php

require_once __DIR__ . '/../helpers/DiagnosisHelper.php';
require_once __DIR__ . '/../helpers/AiMetricsHelper.php';

class ClinicalReportService
{
    private const CLINIC_NAME = 'OphthaMind AI Retinal Screening Centre';
    private const CLINIC_SUBTITLE = 'Fundus Imaging · Deep Learning Ensemble · Clinical Decision Support';

    /**
     * Stream a binary PDF to the browser (attachment).
     *
     * @throws RuntimeException when mPDF is unavailable
     */
    public static function streamPdf(array $data, int $predictionId): void
    {
        $mpdf = self::createMpdf();
        $html = self::buildReportHtml($data);
        $mpdf->WriteHTML($html);

        $filename = self::buildFilename($predictionId, $data);
        $mpdf->Output($filename, 'D');
    }

    /**
     * @return \Mpdf\Mpdf
     */
    private static function createMpdf()
    {
        $autoload = BASE_PATH . '/vendor/autoload.php';
        if (!file_exists($autoload)) {
            throw new RuntimeException(
                'PDF engine not installed. Run: php composer.phar install (requires PHP extensions gd and zip).'
            );
        }

        require_once $autoload;

        return new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 14,
            'margin_right' => 14,
            'margin_top' => 28,
            'margin_bottom' => 22,
            'margin_header' => 8,
            'margin_footer' => 8,
            'default_font' => 'dejavusans',
        ]);
    }

    private static function buildFilename(int $predictionId, array $data): string
    {
        $ic = preg_replace('/[^A-Za-z0-9]/', '', (string)($data['ic'] ?? ''));
        $ic = $ic !== '' ? $ic : 'patient';
        $date = date('Ymd', strtotime((string)($data['created_at'] ?? 'now')));

        return sprintf('OphthaMind_RetinalReport_%s_%s_%d.pdf', $ic, $date, $predictionId);
    }

    public static function buildReportHtml(array $data): string
    {
        $reportId = (int)($data['id'] ?? 0);
        $name = self::esc($data['name'] ?? 'N/A');
        $ic = self::esc($data['ic'] ?? 'N/A');
        $age = self::esc((string)($data['age'] ?? 'N/A'));
        $gender = self::esc($data['gender'] ?? 'N/A');
        $final = DiagnosisHelper::normalize($data['final_result'] ?? '');
        $finalEsc = self::esc($final);
        $confidence = self::formatPercent($data['confidence'] ?? 0);
        $risk = self::esc(ucfirst(strtolower((string)($data['risk_level'] ?? DiagnosisHelper::defaultRisk($final)))));
        $agreement = self::formatPercent($data['model_agreement_score'] ?? 0);
        $created = self::esc(self::formatDateTime($data['created_at'] ?? null));
        $clinician = self::esc($data['doctor_name'] ?? 'Unassigned clinician');

        $cnn = self::esc(DiagnosisHelper::normalize($data['cnn_result'] ?? ''));
        $vgg = self::esc(DiagnosisHelper::normalize($data['vgg_result'] ?? ''));
        $resnet = self::esc(DiagnosisHelper::normalize($data['resnet_result'] ?? ''));
        $cnnC = self::formatPercent($data['cnn_confidence'] ?? 0);
        $vggC = self::formatPercent($data['vgg_confidence'] ?? 0);
        $resC = self::formatPercent($data['resnet_confidence'] ?? 0);
        $cnnA = self::formatPercent($data['cnn_accuracy'] ?? AiMetricsHelper::benchmarkAccuracy('cnn'));
        $vggA = self::formatPercent($data['vgg_accuracy'] ?? AiMetricsHelper::benchmarkAccuracy('vgg16'));
        $resA = self::formatPercent($data['resnet_accuracy'] ?? AiMetricsHelper::benchmarkAccuracy('resnet50'));
        $agrLabel = self::formatPercent($data['agreement_label_pct'] ?? 0);
        $agrAcc = self::formatPercent($data['agreement_accuracy_pct'] ?? 0);
        $agrConf = self::formatPercent($data['agreement_confidence_pct'] ?? 0);

        $recommendation = self::esc(DiagnosisHelper::clinicalNoteFromModels(
            (string)($data['cnn_result'] ?? ''),
            (string)($data['vgg_result'] ?? ''),
            (string)($data['resnet_result'] ?? ''),
            $final
        ));
        $followUp = self::esc(DiagnosisHelper::followUpInterval($final, (string)($data['risk_level'] ?? '')));
        $dxColor = self::diagnosisColor($final);
        $riskColor = self::riskColor((string)($data['risk_level'] ?? ''));

        $imageBlock = self::buildFundusImageBlock($data);
        $headerHtml = self::buildRunningHeader($reportId, $created);
        $footerHtml = self::buildRunningFooter();

        $styles = self::reportStyles();
        $clinicName = self::esc(self::CLINIC_NAME);
        $clinicSub = self::esc(self::CLINIC_SUBTITLE);

        return <<<HTML
{$headerHtml}
{$footerHtml}
<html>
<head>
<meta charset="utf-8">
<style>{$styles}</style>
</head>
<body>

<table class="letterhead" width="100%">
<tr>
<td class="letterhead-brand">
<div class="clinic-name">{$clinicName}</div>
<div class="clinic-sub">{$clinicSub}</div>
</td>
<td class="letterhead-meta" align="right">
<div class="doc-title">RETINAL AI SCREENING REPORT</div>
<div class="doc-ref">Report No. RPT-{$reportId}</div>
<div class="doc-date">Screening: {$created}</div>
</td>
</tr>
</table>

<div class="divider"></div>

<table class="section-title" width="100%"><tr><td>1. Patient identification</td></tr></table>
<table class="info-grid" width="100%">
<tr>
<th width="18%">Full name</th><td width="32%">{$name}</td>
<th width="18%">National ID (IC)</th><td width="32%">{$ic}</td>
</tr>
<tr>
<th>Age</th><td>{$age} years</td>
<th>Gender</th><td>{$gender}</td>
</tr>
</table>

<table class="section-title" width="100%"><tr><td>2. Fundus image reviewed</td></tr></table>
{$imageBlock}

<table class="section-title" width="100%"><tr><td>3. AI ensemble summary (decision support)</td></tr></table>
<table class="summary-box" width="100%">
<tr>
<td width="50%" class="summary-main">
<div class="summary-label">Primary AI diagnosis</div>
<div class="summary-dx" style="color:{$dxColor};">{$finalEsc}</div>
<div class="summary-metrics">
Confidence <strong>{$confidence}</strong> &nbsp;·&nbsp;
Risk <strong style="color:{$riskColor};">{$risk}</strong> &nbsp;·&nbsp;
Agreement <strong>{$agreement}</strong> (label {$agrLabel} · accuracy {$agrAcc} · confidence {$agrConf})
</div>
</td>
<td width="50%" class="summary-clinician">
<div class="summary-label">Performing clinician</div>
<div class="clinician-name">{$clinician}</div>
<div class="summary-note">Image analysed by CNN, VGG16 and ResNet50 with weighted ensemble fusion.</div>
</td>
</tr>
</table>

<table class="section-title" width="100%"><tr><td>4. Per-model outputs</td></tr></table>
<table class="model-table" width="100%">
<thead>
<tr>
<th width="24%">Model architecture</th>
<th width="28%">Predicted class</th>
<th width="16%">Accuracy</th>
<th width="16%">Confidence</th>
<th width="16%">Ensemble weight</th>
</tr>
</thead>
<tbody>
<tr><td>Convolutional Neural Network (CNN)</td><td>{$cnn}</td><td>{$cnnA}</td><td>{$cnnC}</td><td>30%</td></tr>
<tr><td>VGG16 (transfer learning)</td><td>{$vgg}</td><td>{$vggA}</td><td>{$vggC}</td><td>35%</td></tr>
<tr><td>ResNet50 (transfer learning)</td><td>{$resnet}</td><td>{$resA}</td><td>{$resC}</td><td>35%</td></tr>
<tr class="ensemble-row"><td><strong>Ensemble consensus</strong></td><td><strong>{$finalEsc}</strong></td><td>n/a</td><td><strong>{$confidence}</strong></td><td><strong>{$agreement}</strong></td></tr>
</tbody>
</table>

<table class="section-title" width="100%"><tr><td>5. Clinical interpretation &amp; recommended actions</td></tr></table>
<div class="recommendation">
<p>{$recommendation}</p>
<p class="follow-up"><strong>Suggested review interval:</strong> {$followUp}</p>
</div>

<table class="section-title" width="100%"><tr><td>6. Classification reference (four-class screening)</td></tr></table>
<table class="legend-table" width="100%">
<tr>
<td class="leg-normal"><strong>Normal</strong>. No significant retinal abnormality on screening.</td>
<td class="leg-cataract"><strong>Cataract</strong>. Lens opacity pattern. Assess vision impact.</td>
</tr>
<tr>
<td class="leg-dr"><strong>Diabetic Retinopathy</strong>. Vascular retinal changes. Coordinate diabetes care.</td>
<td class="leg-glaucoma"><strong>Glaucoma</strong>. Optic nerve and IOP risk pathway.</td>
</tr>
</table>

<table class="section-title" width="100%"><tr><td>7. Validation &amp; signatures</td></tr></table>
<table class="signatures" width="100%">
<tr>
<td width="50%">
<div class="sig-line"></div>
<div class="sig-label">Reporting ophthalmologist</div>
<div class="sig-hint">Name / MMC No. / Date</div>
</td>
<td width="50%">
<div class="sig-line"></div>
<div class="sig-label">Reviewing clinician (if applicable)</div>
<div class="sig-hint">Name / Signature / Date</div>
</td>
</tr>
</table>

</body>
</html>
HTML;
    }

    private static function buildRunningHeader(int $reportId, string $created): string
    {
        $clinic = self::esc(self::CLINIC_NAME);

        return <<<HTML
<htmlpageheader name="reportHeader">
<table width="100%" class="run-hdr">
<tr>
<td class="run-hdr-left">{$clinic}</td>
<td class="run-hdr-right" align="right">RPT-{$reportId} · {$created}</td>
</tr>
</table>
</htmlpageheader>
<sethtmlpageheader name="reportHeader" value="on" show-this-page="1" />
HTML;
    }

    private static function buildRunningFooter(): string
    {
        return <<<HTML
<htmlpagefooter name="reportFooter">
<table width="100%" class="run-ftr">
<tr>
<td width="70%">AI-assisted report. Ophthalmologist validation required.</td>
<td width="30%" align="right">Page {PAGENO} of {nbpg}</td>
</tr>
</table>
</htmlpagefooter>
<sethtmlpagefooter name="reportFooter" value="on" />
HTML;
    }

    private static function buildFundusImageBlock(array $data): string
    {
        $path = self::resolveImagePath($data['image_path'] ?? '');
        if ($path === null) {
            return '<div class="image-missing">Fundus image file not available for this screening record. Refer to the electronic medical record for the source image.</div>';
        }

        $mime = self::imageMime($path);
        if ($mime === null) {
            return '<div class="image-missing">Fundus image could not be embedded (unsupported format). File: ' . self::esc(basename($path)) . '</div>';
        }

        $binary = @file_get_contents($path);
        if ($binary === false || $binary === '') {
            return '<div class="image-missing">Fundus image could not be read from storage.</div>';
        }

        $b64 = base64_encode($binary);
        $fileLabel = self::esc(basename($path));

        return <<<HTML
<div class="fundus-wrap">
<img class="fundus-img" src="data:{$mime};base64,{$b64}" alt="Fundus photograph" />
<div class="fundus-caption">Source: {$fileLabel}. Left/right eye not specified. Confirm clinically.</div>
</div>
HTML;
    }

    private static function resolveImagePath(?string $imagePath): ?string
    {
        $imagePath = trim((string)$imagePath);
        if ($imagePath === '') {
            return null;
        }

        $relative = ltrim(str_replace(['\\', '..'], ['/', ''], $imagePath), '/');
        $full = BASE_PATH . '/upload/' . $relative;

        return is_file($full) ? $full : null;
    }

    private static function imageMime(string $path): ?string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $map = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
        ];

        if (isset($map[$ext])) {
            return $map[$ext];
        }

        if (function_exists('mime_content_type')) {
            $detected = mime_content_type($path);
            if (is_string($detected) && str_starts_with($detected, 'image/')) {
                return $detected;
            }
        }

        return null;
    }

    private static function formatDateTime(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return date('d M Y, H:i');
        }

        $ts = strtotime($value);
        if ($ts === false) {
            return $value;
        }

        return date('d M Y, H:i', $ts);
    }

    private static function formatPercent($value): string
    {
        $n = is_numeric($value) ? (float)$value : 0.0;
        if ($n > 0 && $n <= 1) {
            $n *= 100;
        }

        return number_format(max(0, min(100, $n)), 1) . '%';
    }

    private static function esc(?string $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function diagnosisColor(string $label): string
    {
        switch (DiagnosisHelper::normalize($label)) {
            case DiagnosisHelper::NORMAL:
                return '#2e7d32';
            case DiagnosisHelper::CATARACT:
                return '#1565c0';
            case DiagnosisHelper::GLAUCOMA:
                return '#6a1b9a';
            case DiagnosisHelper::DIABETIC_RETINOPATHY:
                return '#c62828';
            default:
                return '#37474f';
        }
    }

    private static function riskColor(string $risk): string
    {
        $r = strtolower(trim($risk));
        if ($r === 'high') {
            return '#c62828';
        }
        if ($r === 'medium') {
            return '#ef6c00';
        }

        return '#2e7d32';
    }

    private static function reportStyles(): string
    {
        return <<<'CSS'
body { font-family: dejavusans, sans-serif; font-size: 10pt; color: #1a1a1a; line-height: 1.45; }
.letterhead { margin-bottom: 4mm; }
.clinic-name { font-size: 14pt; font-weight: bold; color: #0d47a1; }
.clinic-sub { font-size: 8.5pt; color: #546e7a; margin-top: 2px; }
.letterhead-meta { vertical-align: top; }
.doc-title { font-size: 11pt; font-weight: bold; color: #263238; letter-spacing: 0.3px; }
.doc-ref, .doc-date { font-size: 8.5pt; color: #607d8b; margin-top: 3px; }
.divider { border-top: 2px solid #0d47a1; margin: 3mm 0 4mm 0; }
.section-title td {
    background: #eceff1;
    font-size: 9pt;
    font-weight: bold;
    color: #263238;
    padding: 5px 8px;
    border-left: 4px solid #0d47a1;
    margin-top: 4mm;
}
.info-grid th {
    background: #f5f7fa;
    font-size: 8.5pt;
    color: #455a64;
    padding: 6px 8px;
    border: 1px solid #cfd8dc;
}
.info-grid td {
    font-size: 9.5pt;
    padding: 6px 8px;
    border: 1px solid #cfd8dc;
}
.fundus-wrap { text-align: center; margin: 3mm 0 4mm 0; }
.fundus-img {
    max-width: 88mm;
    max-height: 70mm;
    border: 1px solid #b0bec5;
    padding: 2px;
    background: #000;
}
.fundus-caption { font-size: 8pt; color: #607d8b; margin-top: 4px; }
.image-missing {
    background: #fff8e1;
    border: 1px dashed #ffb300;
    padding: 10px;
    font-size: 9pt;
    color: #5d4037;
    margin: 3mm 0;
}
.summary-box { border: 1px solid #90a4ae; margin-bottom: 3mm; }
.summary-box td { padding: 10px 12px; vertical-align: top; border: 1px solid #cfd8dc; }
.summary-label { font-size: 8pt; color: #607d8b; text-transform: uppercase; letter-spacing: 0.4px; }
.summary-dx { font-size: 16pt; font-weight: bold; margin: 4px 0 6px 0; }
.summary-metrics { font-size: 9.5pt; color: #37474f; }
.clinician-name { font-size: 11pt; font-weight: bold; color: #263238; margin: 4px 0; }
.summary-note { font-size: 8pt; color: #78909c; }
.model-table th {
    background: #0d47a1;
    color: #fff;
    font-size: 8.5pt;
    padding: 6px 8px;
    text-align: left;
}
.model-table td {
    font-size: 9pt;
    padding: 6px 8px;
    border-bottom: 1px solid #e0e0e0;
}
.model-table tr.ensemble-row td { background: #e3f2fd; }
.recommendation {
    border: 1px solid #b0bec5;
    background: #fafafa;
    padding: 10px 12px;
    margin-bottom: 3mm;
    font-size: 9.5pt;
}
.follow-up { margin-top: 8px; font-size: 9pt; }
.legend-table td {
    font-size: 8.5pt;
    padding: 6px 8px;
    border: 1px solid #e0e0e0;
    vertical-align: top;
}
.leg-normal { background: #e8f5e9; }
.leg-cataract { background: #e3f2fd; }
.leg-dr { background: #ffebee; }
.leg-glaucoma { background: #f3e5f5; }
.signatures td { padding-top: 8mm; vertical-align: bottom; }
.sig-line { border-bottom: 1px solid #37474f; height: 10mm; }
.sig-label { font-size: 9pt; font-weight: bold; margin-top: 4px; }
.sig-hint { font-size: 8pt; color: #78909c; }
.run-hdr { font-size: 8pt; color: #607d8b; border-bottom: 1px solid #cfd8dc; padding-bottom: 3px; }
.run-ftr { font-size: 7.5pt; color: #78909c; border-top: 1px solid #e0e0e0; padding-top: 3px; }
CSS;
    }
}
