@extends('layouts.email')

@section('title')
New message on {{ $projectName }}
@endsection

@section('content')

<tr>
    <td align="center" style="padding:24px; background:linear-gradient(135deg,#0066CC,#003D7A);">
        <img src="https://monkeysraiser.com/logo.svg"
             alt="MonkeysRaiser"
             width="140"
             style="display:block; margin-bottom:16px;">
        <div style="color:#E5F0FF; font-size:11px; text-transform:uppercase; letter-spacing:0.14em; font-weight:600;">
            New message
        </div>
        <div style="color:#FFFFFF; font-size:22px; font-weight:800; margin-top:6px;">
            New activity on {{ $projectName }}
        </div>
    </td>
</tr>

<tr>
    <td style="background:#FFFFFF; padding:24px;">
        <p style="margin:0 0 14px; font-size:14px; color:#111827;">
            Hi,
        </p>

        <p style="margin:0 0 14px; font-size:14px; color:#111827; line-height:1.5;">
            <strong>{{ $authorName }}</strong>
            @if (!empty($authorEmail))
            ({{ $authorEmail }})
            @endif
            sent a new message in a conversation related to <strong>{{ $projectName }}</strong>.
        </p>

        <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 14px; width:100%; border-collapse:collapse;">
            <tr>
                <td style="font-size:12px; color:#6B7280; padding:0 0 4px;">
                    Subject
                </td>
            </tr>
            <tr>
                <td style="font-size:14px; color:#111827; font-weight:600; padding:0 0 10px;">
                    {{ $subject }}
                </td>
            </tr>
            <tr>
                <td style="font-size:12px; color:#6B7280; padding:8px 0 4px;">
                    Message preview
                </td>
            </tr>
            <tr>
                <td style="font-size:13px; color:#4B5563; line-height:1.6; padding:0 0 10px;">
                    {{ $snippet }}
                </td>
            </tr>
        </table>

        <table role="presentation" cellpadding="0" cellspacing="0" style="margin:10px 0 20px;">
            <tr>
                <td>
                    <a href="{{ $projectUrl }}"
                       style="display:inline-block; padding:10px 20px; color:#FFFFFF; background:#0066CC; text-decoration:none; font-size:14px; font-weight:600; border-radius:999px;">
                        View conversation
                    </a>
                </td>
            </tr>
        </table>

        <p style="margin:0; font-size:12px; color:#6B7280; line-height:1.6;">
            You’re receiving this notification because you’re a participant in this conversation on MonkeysRaiser.
        </p>
    </td>
</tr>

@endsection
