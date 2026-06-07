<?php

declare(strict_types=1);

namespace Tests\Integration\Notification\Channel\Doubles;

use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Mail\Message;
use Illuminate\Mail\PendingMail;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Recording test double for `Illuminate\Contracts\Mail\Mailer`.
 *
 * Why a hand-rolled fake rather than a Mockery double:
 *  the `Message` callback that the driver passes to `send()` is the
 *  thing the test wants to introspect. Capturing the real `Message`
 *  state after the callback runs is more direct than asserting on a
 *  mocked sequence of method calls, and the test reads as
 *  "send-then-inspect-result," which mirrors the production flow.
 *
 * The methods the driver does *not* use (`to`, `bcc`, `raw`, `sendNow`)
 * throw `BadMethodCallException`. That signals "the driver wandered
 * outside the contract this fake supports" — preferable to a silent
 * no-op that would mask a regression in the driver's call shape.
 */
final class RecordingMailer implements Mailer
{
    /** @var array<int, array{from: array<string, ?string>, to: array<string, ?string>, subject: string, html: ?string, text: ?string}> */
    public array $sentMessages = [];

    public ?\Throwable $throwOnNextSend = null;

    public function to($users): PendingMail
    {
        throw new \BadMethodCallException('not used by EmailChannelDriver');
    }

    public function bcc($users): PendingMail
    {
        throw new \BadMethodCallException('not used by EmailChannelDriver');
    }

    public function raw($text, $callback): void
    {
        throw new \BadMethodCallException('not used by EmailChannelDriver');
    }

    public function send($view, array $data = [], $callback = null): void
    {
        if ($this->throwOnNextSend !== null) {
            $e = $this->throwOnNextSend;
            $this->throwOnNextSend = null;
            throw $e;
        }

        $symfonyMessage = new Email;
        $message = new Message($symfonyMessage);

        if (is_callable($callback)) {
            $callback($message);
        }

        $email = $message->getSymfonyMessage();

        $this->sentMessages[] = [
            'from' => $this->addressesToArray($email->getFrom()),
            'to' => $this->addressesToArray($email->getTo()),
            'subject' => (string) $email->getSubject(),
            'html' => $email->getHtmlBody(),
            'text' => $email->getTextBody(),
        ];
    }

    public function sendNow($mailable, ?array $data = null, $callback = null): void
    {
        // Part of the Mailer contract since Laravel 11. The driver does
        // not use it, so the fake refuses to silently no-op.
        throw new \BadMethodCallException('not used by EmailChannelDriver');
    }

    /**
     * @param  iterable<Address>  $addresses
     * @return array<string, ?string>
     */
    private function addressesToArray(iterable $addresses): array
    {
        $result = [];
        foreach ($addresses as $address) {
            $name = $address->getName();
            $result[$address->getAddress()] = $name === '' ? null : $name;
        }

        return $result;
    }
}
