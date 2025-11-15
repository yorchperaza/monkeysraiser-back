@extends('layouts.email')

@section('title')
You’ve been invited to collaborate on <?= htmlspecialchars($projectName ?? '', ENT_QUOTES, 'UTF-8') ?>
@endsection

@section('content')

<tr>
    <td align="center" style="padding:24px; background:linear-gradient(135deg,#0066CC,#003D7A);">
        <img src="https://back.monkeysraiser.com/logo.png"
             alt="MonkeysRaiser"
             width="140"
             style="display:block; margin-bottom:16px;">
        <div style="color:#E5F0FF; font-size:11px; text-transform:uppercase; letter-spacing:0.14em; font-weight:600;">
            Contributor Invite
        </div>
        <div style="color:#FFF; font-size:22px; font-weight:800; margin-top:6px;">
            You’ve been invited to collaborate
        </div>
    </td>
</tr>

<tr>
    <td style="background:#FFF; padding:24px;">
        <p style="margin:0 0 14px; font-size:14px; color:#111827;">Hi,</p>

        <p style="margin:0 0 14px; font-size:14px; color:#111827; line-height:1.5;">
            <strong><?= htmlspecialchars($inviterName ?? '', ENT_QUOTES, 'UTF-8') ?></strong>
            invited you to collaborate on the project
            <strong><?= htmlspecialchars($projectName ?? '', ENT_QUOTES, 'UTF-8') ?></strong>
            on MonkeysRaiser.
        </p>

        <?php if (!empty($projectTagline)) { ?>
            <p style="margin:0 0 16px; font-size:13px; color:#4B5563;">
                <?= htmlspecialchars($projectTagline, ENT_QUOTES, 'UTF-8') ?>
            </p>
        <?php } ?>

        <p style="margin:0 0 16px; font-size:13px; color:#4B5563; line-height:1.6;">
            As a contributor you’ll be able to help update content, add traction, and collaborate
            with the founder team on the project profile.
        </p>

        <table role="presentation" cellpadding="0" cellspacing="0" style="margin:16px 0 20px;">
            <tr>
                <td>
                    <a href="<?= htmlspecialchars($projectUrl ?? '#', ENT_QUOTES, 'UTF-8') ?>"
                       style="display:inline-block; padding:10px 20px; color:#FFF; background:#0066CC; text-decoration:none; font-size:14px; font-weight:600; border-radius:999px;">
                        View Project & Confirm
                    </a>
                </td>
            </tr>
        </table>

        <p style="margin:0; font-size:12px; color:#6B7280;">
            If you don’t have a MonkeysRaiser account yet, you will be able to create one after clicking the button.
        </p>
    </td>
</tr>

@endsection
