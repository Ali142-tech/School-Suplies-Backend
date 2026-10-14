<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VendorApplicationReviewed extends Notification
{
    use Queueable;

    public function __construct(private readonly string $status, private readonly ?string $domain = null, private readonly ?string $reason = null) {}

    public function via(object $notifiable): array
    {
        return config('mail.default') && config('mail.default') !== 'log' ? ['database', 'mail'] : ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'vendor_application_'.$this->status,
            'title' => $this->status === 'approved' ? 'Your vendor application was approved' : 'Your vendor application needs attention',
            'message' => $this->status === 'approved' ? 'Your store is approved. Assigned hostname: '.($this->domain ?? 'pending manual setup').'. Manual Vercel/DNS setup is still required before it serves your store.' : 'Your application was rejected. Reason: '.($this->reason ?? 'Please contact support.'),
            'status' => $this->status, 'domain' => $this->domain, 'reason' => $this->reason,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->status === 'approved' ? 'Vendor application approved' : 'Vendor application update')
            ->line($this->status === 'approved' ? 'Your vendor application has been approved.' : 'Your vendor application was not approved.');
        if ($this->domain) {
            $mail->line('Assigned hostname: '.$this->domain.'. Manual DNS/Vercel configuration is still required.');
        }
        if ($this->reason) {
            $mail->line('Reason: '.$this->reason);
        }

        return $mail;
    }
}
