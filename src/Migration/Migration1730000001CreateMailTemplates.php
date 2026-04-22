<?php declare(strict_types=1);

namespace Laenen\GuestMerge\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

class Migration1730000001CreateMailTemplates extends MigrationStep
{
    public const VERIFY_TYPE   = 'laenen_guest_merge_verify';
    public const COMPLETE_TYPE = 'laenen_guest_merge_completed';

    public function getCreationTimestamp(): int
    {
        return 1730000001;
    }

    public function update(Connection $connection): void
    {
        $enLangId = $this->getLanguageIdByLocale($connection, 'en-GB');
        $deLangId = $this->getLanguageIdByLocale($connection, 'de-DE');
        $defaultLangId = Defaults::LANGUAGE_SYSTEM;

        $this->createMailTemplate(
            $connection,
            self::VERIFY_TYPE,
            'Laenen: Confirm guest order merge',
            'Laenen: Zusammenführung von Gastbestellungen bestätigen',
            $this->verifySubjectEn(),
            $this->verifyBodyHtmlEn(),
            $this->verifyBodyPlainEn(),
            $this->verifySubjectDe(),
            $this->verifyBodyHtmlDe(),
            $this->verifyBodyPlainDe(),
            $defaultLangId,
            $enLangId,
            $deLangId
        );

        $this->createMailTemplate(
            $connection,
            self::COMPLETE_TYPE,
            'Laenen: Guest orders merged',
            'Laenen: Gastbestellungen zusammengeführt',
            'Your previous orders are now in your account',
            $this->completedBodyHtmlEn(),
            $this->completedBodyPlainEn(),
            'Ihre früheren Bestellungen sind jetzt in Ihrem Konto',
            $this->completedBodyHtmlDe(),
            $this->completedBodyPlainDe(),
            $defaultLangId,
            $enLangId,
            $deLangId
        );
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function createMailTemplate(
        Connection $connection,
        string $technicalName,
        string $typeNameEn,
        string $typeNameDe,
        string $subjectEn,
        string $bodyHtmlEn,
        string $bodyPlainEn,
        string $subjectDe,
        string $bodyHtmlDe,
        string $bodyPlainDe,
        ?string $defaultLangId,
        ?string $enLangId,
        ?string $deLangId
    ): void {
        // Check if mail template type already exists
        $existingTypeId = $connection->fetchOne(
            'SELECT LOWER(HEX(id)) FROM mail_template_type WHERE technical_name = :tn',
            ['tn' => $technicalName]
        );

        if ($existingTypeId) {
            return; // Already installed
        }

        $typeId = Uuid::randomBytes();
        $templateId = Uuid::randomBytes();
        $now = (new \DateTime())->format('Y-m-d H:i:s.v');

        $connection->insert('mail_template_type', [
            'id' => $typeId,
            'technical_name' => $technicalName,
            'available_entities' => json_encode([
                'customer' => 'customer',
                'mergeRequest' => null,
            ]),
            'created_at' => $now,
        ]);

        // Type translations
        $this->insertTypeTranslation($connection, $typeId, $defaultLangId, $typeNameEn, $now);
        if ($enLangId && $enLangId !== $defaultLangId) {
            $this->insertTypeTranslation($connection, $typeId, $enLangId, $typeNameEn, $now);
        }
        if ($deLangId && $deLangId !== $defaultLangId) {
            $this->insertTypeTranslation($connection, $typeId, $deLangId, $typeNameDe, $now);
        }

        $connection->insert('mail_template', [
            'id' => $templateId,
            'mail_template_type_id' => $typeId,
            'system_default' => 1,
            'created_at' => $now,
        ]);

        // Template translations
        $this->insertTemplateTranslation(
            $connection, $templateId, $defaultLangId,
            $subjectEn, $bodyHtmlEn, $bodyPlainEn, $now
        );
        if ($enLangId && $enLangId !== $defaultLangId) {
            $this->insertTemplateTranslation(
                $connection, $templateId, $enLangId,
                $subjectEn, $bodyHtmlEn, $bodyPlainEn, $now
            );
        }
        if ($deLangId && $deLangId !== $defaultLangId) {
            $this->insertTemplateTranslation(
                $connection, $templateId, $deLangId,
                $subjectDe, $bodyHtmlDe, $bodyPlainDe, $now
            );
        }
    }

    private function insertTypeTranslation(Connection $c, string $typeId, string $langId, string $name, string $now): void
    {
        $c->executeStatement(
            'INSERT IGNORE INTO mail_template_type_translation
                (mail_template_type_id, language_id, name, created_at)
             VALUES (:tid, :lid, :name, :now)',
            ['tid' => $typeId, 'lid' => Uuid::fromHexToBytes($langId), 'name' => $name, 'now' => $now]
        );
    }

    private function insertTemplateTranslation(
        Connection $c, string $templateId, string $langId,
        string $subject, string $html, string $plain, string $now
    ): void {
        $c->executeStatement(
            'INSERT IGNORE INTO mail_template_translation
                (mail_template_id, language_id, sender_name, subject, description,
                 content_html, content_plain, created_at)
             VALUES (:tid, :lid, :sn, :subj, :desc, :html, :plain, :now)',
            [
                'tid' => $templateId,
                'lid' => Uuid::fromHexToBytes($langId),
                'sn' => '{{ salesChannel.name }}',
                'subj' => $subject,
                'desc' => 'Laenen guest merge notification',
                'html' => $html,
                'plain' => $plain,
                'now' => $now,
            ]
        );
    }

    private function getLanguageIdByLocale(Connection $c, string $locale): ?string
    {
        $sql = 'SELECT LOWER(HEX(language.id)) FROM language
                INNER JOIN locale ON locale.id = language.locale_id
                WHERE locale.code = :code LIMIT 1';
        $id = $c->fetchOne($sql, ['code' => $locale]);
        return $id ?: null;
    }

    // ----- Mail template content -----

    private function verifySubjectEn(): string
    {
        return 'Confirm merging your previous orders into your account';
    }

    private function verifySubjectDe(): string
    {
        return 'Bestätigen Sie das Zusammenführen Ihrer früheren Bestellungen';
    }

    private function verifyBodyHtmlEn(): string
    {
        return <<<'HTML'
<div style="font-family: Arial, sans-serif; color: #333; max-width: 600px;">
    <p>Hello {{ customer.firstName }} {{ customer.lastName }},</p>

    <p>We received a request to merge {{ candidateOrderCount }} previous guest order(s)
    placed with the email address <strong>{{ customer.email }}</strong> into your
    account.</p>

    <p>To confirm this request, click the link below:</p>

    <p style="margin: 24px 0;">
        <a href="{{ confirmUrl }}"
           style="background:#0073e6;color:#fff;padding:12px 24px;
                  text-decoration:none;border-radius:4px;display:inline-block;">
            Confirm and merge my orders
        </a>
    </p>

    <p>Or read this confirmation code to our customer service representative:</p>

    <p style="font-size:24px;font-weight:bold;letter-spacing:4px;
              background:#f5f5f5;padding:12px;text-align:center;
              border-radius:4px;font-family:monospace;">
        {{ shortCode }}
    </p>

    <p style="color:#666;font-size:13px;">
        This link and code expire on {{ expiresAt|date('Y-m-d H:i') }}.
        If you did not request this, you can safely ignore this email &mdash; nothing
        will be merged without your confirmation.
    </p>

    <p>Thanks,<br>The Laenen Team</p>
</div>
HTML;
    }

    private function verifyBodyPlainEn(): string
    {
        return <<<'TXT'
Hello {{ customer.firstName }} {{ customer.lastName }},

We received a request to merge {{ candidateOrderCount }} previous guest order(s)
placed with the email address {{ customer.email }} into your account.

To confirm this request, open the link below:
{{ confirmUrl }}

Or read this confirmation code to our customer service representative:

    {{ shortCode }}

This link and code expire on {{ expiresAt|date('Y-m-d H:i') }}.
If you did not request this, you can safely ignore this email - nothing will be
merged without your confirmation.

Thanks,
The Laenen Team
TXT;
    }

    private function verifyBodyHtmlDe(): string
    {
        return <<<'HTML'
<div style="font-family: Arial, sans-serif; color: #333; max-width: 600px;">
    <p>Hallo {{ customer.firstName }} {{ customer.lastName }},</p>

    <p>Wir haben eine Anfrage zum Zusammenführen von {{ candidateOrderCount }}
    früheren Gastbestellung(en) mit der E-Mail-Adresse <strong>{{ customer.email }}</strong>
    in Ihr Konto erhalten.</p>

    <p>Um diese Anfrage zu bestätigen, klicken Sie auf den folgenden Link:</p>

    <p style="margin: 24px 0;">
        <a href="{{ confirmUrl }}"
           style="background:#0073e6;color:#fff;padding:12px 24px;
                  text-decoration:none;border-radius:4px;display:inline-block;">
            Bestellungen zusammenführen
        </a>
    </p>

    <p>Oder geben Sie diesen Bestätigungscode an unseren Kundenservice durch:</p>

    <p style="font-size:24px;font-weight:bold;letter-spacing:4px;
              background:#f5f5f5;padding:12px;text-align:center;
              border-radius:4px;font-family:monospace;">
        {{ shortCode }}
    </p>

    <p style="color:#666;font-size:13px;">
        Dieser Link und Code laufen am {{ expiresAt|date('d.m.Y H:i') }} ab.
        Wenn Sie dies nicht angefragt haben, k&ouml;nnen Sie diese E-Mail
        ignorieren &ndash; ohne Ihre Best&auml;tigung wird nichts zusammengef&uuml;hrt.
    </p>

    <p>Vielen Dank,<br>Ihr Laenen-Team</p>
</div>
HTML;
    }

    private function verifyBodyPlainDe(): string
    {
        return <<<'TXT'
Hallo {{ customer.firstName }} {{ customer.lastName }},

Wir haben eine Anfrage zum Zusammenfuehren von {{ candidateOrderCount }} frueheren
Gastbestellung(en) mit der E-Mail-Adresse {{ customer.email }} in Ihr Konto
erhalten.

Um diese Anfrage zu bestaetigen, oeffnen Sie den folgenden Link:
{{ confirmUrl }}

Oder geben Sie diesen Bestaetigungscode an unseren Kundenservice durch:

    {{ shortCode }}

Dieser Link und Code laufen am {{ expiresAt|date('d.m.Y H:i') }} ab.
Wenn Sie dies nicht angefragt haben, koennen Sie diese E-Mail ignorieren - ohne
Ihre Bestaetigung wird nichts zusammengefuehrt.

Vielen Dank,
Ihr Laenen-Team
TXT;
    }

    private function completedBodyHtmlEn(): string
    {
        return <<<'HTML'
<div style="font-family: Arial, sans-serif; color: #333; max-width: 600px;">
    <p>Hello {{ customer.firstName }} {{ customer.lastName }},</p>

    <p>{{ movedOrderCount }} previous order(s) placed as a guest have been merged
    into your account. You can view them in your order history.</p>

    <p style="margin:24px 0;">
        <a href="{{ ordersUrl }}"
           style="background:#0073e6;color:#fff;padding:12px 24px;
                  text-decoration:none;border-radius:4px;display:inline-block;">
            View my orders
        </a>
    </p>

    <p>Thanks,<br>The Laenen Team</p>
</div>
HTML;
    }

    private function completedBodyPlainEn(): string
    {
        return <<<'TXT'
Hello {{ customer.firstName }} {{ customer.lastName }},

{{ movedOrderCount }} previous order(s) placed as a guest have been merged into
your account. You can view them in your order history:

{{ ordersUrl }}

Thanks,
The Laenen Team
TXT;
    }

    private function completedBodyHtmlDe(): string
    {
        return <<<'HTML'
<div style="font-family: Arial, sans-serif; color: #333; max-width: 600px;">
    <p>Hallo {{ customer.firstName }} {{ customer.lastName }},</p>

    <p>{{ movedOrderCount }} fr&uuml;here Bestellung(en) als Gast wurden in Ihr
    Konto zusammengef&uuml;hrt. Sie k&ouml;nnen sie in Ihrer
    Bestellhistorie einsehen.</p>

    <p style="margin:24px 0;">
        <a href="{{ ordersUrl }}"
           style="background:#0073e6;color:#fff;padding:12px 24px;
                  text-decoration:none;border-radius:4px;display:inline-block;">
            Meine Bestellungen ansehen
        </a>
    </p>

    <p>Vielen Dank,<br>Ihr Laenen-Team</p>
</div>
HTML;
    }

    private function completedBodyPlainDe(): string
    {
        return <<<'TXT'
Hallo {{ customer.firstName }} {{ customer.lastName }},

{{ movedOrderCount }} fruehere Bestellung(en) als Gast wurden in Ihr Konto
zusammengefuehrt. Sie koennen sie in Ihrer Bestellhistorie einsehen:

{{ ordersUrl }}

Vielen Dank,
Ihr Laenen-Team
TXT;
    }
}
