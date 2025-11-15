@extends('layouts.email')

@section('title')
Reset your MonkeysRaiser password
@endsection

@section('content')

<tr>
    <td align="center" style="padding:24px; background:linear-gradient(135deg,#0066CC,#003D7A);">
        <img src="https://monkeysraiser.com/logo.svg"
             alt="MonkeysRaiser"
             width="140"
             style="display:block; margin-bottom:16px;">
        <div style="color:#E5F0FF; font-size:11px; text-transform:uppercase; letter-spacing:0.14em; font-weight:600;">
            Password reset
        </div>
        <div style="color:#FFFFFF; font-size:22px; font-weight:800; margin-top:6px;">
            Reset your password
        </div>
    </td>
</tr>

<tr>
    <td style="background:#FFFFFF; padding:24px;">
        <?php if (!empty($fullName)) { ?>
            <p style="margin:0 0 14px; font-size:14px; color:#111827;">
                Hi <?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?>,
            </p>
        <?php } else { ?>
            <p style="margin:0 0 14px; font-size:14px; color:#111827;">
                Hi there,
            </p>
        <?php } ?>

        <p style="margin:0 0 14px; font-size:14px; color:#111827; line-height:1.5;">
            We received a request to reset the password for your MonkeysRaiser account
            <?php if (!empty($email)) { ?>
                (<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>)
            <?php } ?>
            .
        </p>

        <p style="margin:0 0 16px; font-size:13px; color:#4B5563; line-height:1.6;">
            If you made this request, click the button below to choose a new password.
        </p>

        <table role="presentation" cellpadding="0" cellspacing="0" style="margin:16px 0 20px;">
            <tr>
                <td>
                    <a href="<?= htmlspecialchars($resetUrl ?? '#', ENT_QUOTES, 'UTF-8') ?>"
                       style="display:inline-block; padding:10px 20px; color:#FFFFFF; background:#0066CC; text-decoration:none; font-size:14px; font-weight:600; border-radius:999px;">
                        Reset password
                    </a>
                </td>
            </tr>
        </table>

        <p style="margin:0 0 10px; font-size:12px; color:#6B7280; line-height:1.6;">
            This link will expire in about
            <?= htmlspecialchars((string)($ttlMinutes ?? 60), ENT_QUOTES, 'UTF-8') ?> minutes for security reasons.
        </p>

        <p style="margin:0 0 10px; font-size:12px; color:#6B7280; line-height:1.6;">
            If the button doesn’t work, copy and paste this link into your browser:
            <br>
            <span style="word-break:break-all; color:#374151; font-size:11px;">
                <?= htmlspecialchars($resetUrl ?? '', ENT_QUOTES, 'UTF-8') ?>
            </span>
        </p>

        <p style="margin:0; font-size:12px; color:#6B7280; line-height:1.6;">
            If you didn’t request a password reset, you can safely ignore this email. Your password will stay the same.
        </p>
    </td>
</tr>

@endsection
