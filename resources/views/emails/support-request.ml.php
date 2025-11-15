@extends('layouts.email')

@section('title')
New support request
@endsection

@section('content')

<tr>
    <td align="center" style="padding:24px; background:linear-gradient(135deg,#0066CC,#003D7A);">
        <img src="https://back.monkeysraiser.com/logo.png"
             alt="MonkeysRaiser"
             width="140"
             style="display:block; margin-bottom:16px;">
        <div style="color:#E5F0FF; font-size:11px; text-transform:uppercase; letter-spacing:0.14em; font-weight:600;">
            Support request
        </div>
        <div style="color:#FFFFFF; font-size:22px; font-weight:800; margin-top:6px;">
            New message from MonkeysRaiser
        </div>
    </td>
</tr>

<?php
$safeSubject      = htmlspecialchars($subjectLine ?? '(no subject)', ENT_QUOTES, 'UTF-8');
$safeReporterEmail = htmlspecialchars($reporterEmail ?? '', ENT_QUOTES, 'UTF-8');
$safeReporterName  = htmlspecialchars($reporterName ?? '', ENT_QUOTES, 'UTF-8');
$safeUserId        = isset($userId) ? (int) $userId : null;
$rawDescription    = (string)($description ?? '');
$safeDescription   = nl2br(htmlspecialchars($rawDescription, ENT_QUOTES, 'UTF-8'));
?>

<tr>
    <td style="background:#FFFFFF; padding:24px;">
        <p style="margin:0 0 14px; font-size:14px; color:#111827;">
            Hi Jorge,
        </p>

        <p style="margin:0 0 10px; font-size:13px; color:#374151; line-height:1.6;">
            You just received a new support request from
            <?php if (!empty($safeReporterName)) { ?>
                <strong><?= $safeReporterName ?></strong>
            <?php } ?>
            <?php if (!empty($safeReporterEmail)) { ?>
                <span style="color:#2563EB;">
                    (<?= $safeReporterEmail ?>)
                </span>
            <?php } ?>
            <?php if ($safeUserId) { ?>
                <span style="color:#6B7280; font-size:12px;">
                    • User ID: <?= $safeUserId ?>
                </span>
            <?php } ?>
        </p>

        <table role="presentation" cellpadding="0" cellspacing="0" style="margin:16px 0 18px; width:100%; border-collapse:collapse;">
            <tr>
                <td style="padding:10px 12px; border-radius:12px; background:#F3F4F6;">
                    <div style="margin-bottom:6px; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.08em; color:#6B7280;">
                        Subject
                    </div>
                    <div style="font-size:14px; color:#111827; font-weight:600;">
                        <?= $safeSubject ?>
                    </div>
                </td>
            </tr>
        </table>

        <div style="margin:0 0 18px; font-size:13px; color:#111827; line-height:1.6;">
            <div style="margin-bottom:6px; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.08em; color:#6B7280;">
                Description
            </div>
            <div><?= $safeDescription ?></div>
        </div>

        <?php if (!empty($hasAttachments) && !empty($attachments) && is_array($attachments)) { ?>
            <div style="margin:0 0 16px; font-size:13px; color:#111827; line-height:1.6;">
                <div style="margin-bottom:6px; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.08em; color:#6B7280;">
                    Attachments
                </div>
                <ul style="margin:0; padding-left:18px; font-size:12px; color:#374151;">
                    <?php foreach ($attachments as $file) {
                        $name = htmlspecialchars((string)($file['name'] ?? 'attachment'), ENT_QUOTES, 'UTF-8');
                        $type = htmlspecialchars((string)($file['contentType'] ?? ''), ENT_QUOTES, 'UTF-8');
                        $size = (int)($file['sizeBytes'] ?? 0);

                        if ($size < 1024) {
                            $sizeLabel = $size . ' B';
                        } elseif ($size < 1024 * 1024) {
                            $sizeLabel = round($size / 1024, 1) . ' KB';
                        } else {
                            $sizeLabel = round($size / (1024 * 1024), 1) . ' MB';
                        }
                        ?>
                        <li style="margin:0 0 2px;">
                            <strong><?= $name ?></strong>
                            <span style="color:#6B7280;">
                                (<?= $sizeLabel ?><?= $type ? ' • ' . $type : '' ?>)
                            </span>
                        </li>
                    <?php } ?>
                </ul>
                <p style="margin:8px 0 0; font-size:11px; color:#9CA3AF;">
                    Files are attached to this email.
                </p>
            </div>
        <?php } ?>

        <p style="margin:0; font-size:12px; color:#6B7280; line-height:1.6;">
            You can reply directly to this email to continue the conversation with the user.
        </p>
    </td>
</tr>

@endsection
