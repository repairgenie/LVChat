<?php

declare(strict_types=1);

/**
 * Terms of Service and Privacy Policy management.
 *
 * Content is stored in `server_config` as HTML authored in the admin dashboard
 * with the tiptap editor. Boilerplate is generated on first access and based on
 * US federal law (COPPA/Children's Online Privacy Protection Act) plus Nevada
 * Revised Statutes NRS 597.790 (online privacy disclosure), NRS 597.795
 * (parent/guardian rights) and Nevada venue for disputes.
 */
final class LegalService
{
    public const TAGS = ['p', 'h1', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'strong', 'em', 'u', 's', 'a', 'blockquote', 'code', 'pre', 'hr', 'br'];

    public static function get(string $which): string
    {
        $stored = trim((string) (config_get('legal_' . $which, '') ?? ''));
        return $stored !== '' ? $stored : self::boilerplate($which);
    }

    public static function save(string $which, string $html): void
    {
        config_set('legal_' . $which, self::sanitize($html));
    }

    /**
     * Whitelist HTML from the tiptap editor. Only allowed tags survive;
     * event-handler attributes and javascript: URLs are stripped.
     */
    public static function sanitize(string $html): string
    {
        $html = (string) $html;
        // Drop dangerous whole elements (and their content).
        $html = preg_replace('#<(script|style|iframe|object|embed|form|input|textarea|select|link|meta|base)[^>]*>.*?</\1>#is', '', $html);
        $html = preg_replace('#<(script|style|iframe|object|embed|form|input|textarea|select|link|meta|base)[^>]*/?>#is', '', $html);
        // Allow a bare <a> name only if it is a safe http(s) or relative link.
        $html = preg_replace_callback(
            '#<(/?)([a-zA-Z0-9]+)((?:\s+[a-zA-Z][a-zA-Z0-9:]*\s*=\s*(?:"[^"]*"|\'[^\']*\'))*)(\s*/?)>#',
            function (array $m): string {
                $tag = strtolower($m[2]);
                if (!in_array($tag, self::TAGS, true)) {
                    return '';
                }
                $attrs = '';
                if (preg_match_all('#([a-zA-Z][a-zA-Z0-9:]*)\s*=\s*("[^"]*"|\'[^\']*\')#', $m[3], $am)) {
                    foreach ($am[1] as $i => $name) {
                        $name = strtolower($name);
                        if (in_array($name, ['onerror', 'onload', 'onclick', 'onmouseover', 'onfocus', 'onblur', 'onchange', 'onkeydown', 'onkeyup', 'onkeypress', 'oncontextmenu', 'oncopy', 'oncut', 'onpaste', 'ondrag', 'ondrop', 'onsubmit', 'onreset', 'onselect', 'onwheel'], true)) {
                            continue;
                        }
                        if ($name === 'href' || $name === 'src') {
                            $val = trim($am[2][$i], "\"'");
                            if (preg_match('#^(javascript|data|vbscript)\s*:#i', $val)) {
                                continue;
                            }
                            $attrs .= ' ' . $name . '="' . h($val) . '"';
                        } elseif ($tag === 'a' && $name === 'target' && $am[2][$i] === '"blank"') {
                            $attrs .= ' target="_blank" rel="noopener"';
                        }
                    }
                }
                return '<' . $m[1] . $tag . $attrs . $m[4] . '>';
            },
            $html
        );
        return trim((string) $html);
    }

    /** Nevada + US boilerplate, authored in the same tags tiptap emits. */
    public static function boilerplate(string $which): string
    {
        $site = h((string) (config_get('site_name', 'LVChat') ?: 'LVChat'));
        $url = h(base_url());
        if ($which === 'privacy') {
            return self::privacyBoilerplate($site, $url);
        }
        return self::termsBoilerplate($site, $url);
    }

    private static function termsBoilerplate(string $site, string $url): string
    {
        return "<h1>Terms of Service</h1>
<p>Last updated: " . gmdate('F j, Y') . "</p>
<p>Welcome to <strong>$site</strong> (<code>$url</code>). These Terms of Service (&ldquo;Terms&rdquo;) govern your access to and use of the service. By registering an account, joining as a guest, or otherwise using the service, you agree to be bound by these Terms.</p>
<h2>1. Eligibility</h2>
<p>The service is intended for users who are at least 18 years of age. By creating an account or joining as a guest, you certify that you are at least 18 years old and are of legal age to enter into this agreement in your jurisdiction. If you are under 18, you may not use the service.</p>
<h2>2. Accounts</h2>
<ul>
<li>You are responsible for maintaining the confidentiality of your account credentials and for all activity that occurs under your account.</li>
<li>You must provide accurate information when registering. You agree to notify us promptly of any unauthorized use of your account.</li>
<li>Registration may be subject to approval by the operators. Accounts may be placed in a pending or suspended state at any time for policy or legal reasons.</li>
</ul>
<h2>3. Acceptable use</h2>
<p>You agree not to use the service to:</p>
<ul>
<li>Post content that is unlawful, harassing, abusive, threatening, defamatory, obscene, or otherwise objectionable;</li>
<li>Post content that violates the rights of others, including intellectual property or privacy rights;</li>
<li>Harass, stalk, or bully any person, or publish another person&rsquo;s personal information without consent (&ldquo;doxxing&rdquo;);</li>
<li>Attempt to gain unauthorized access to the service, other accounts, or connected systems;</li>
<li>Send unsolicited advertising, spam, or other bulk messages;</li>
<li>Interfere with the operation of the service or circumvent any technical protection.</li>
</ul>
<p>We may remove content and suspend, ban, or terminate accounts that violate these Terms, at our sole discretion and without prior notice.</p>
<h2>4. User content</h2>
<p>You retain ownership of the content you post. By posting content, you grant us a non-exclusive, worldwide, royalty-free license to store, display, and transmit that content as necessary to operate the service. We may retain archives of content as required for operational, legal, and safety purposes, even after you delete it from public view.</p>
<h2>5. Moderation and reporting</h2>
<p>The service uses automated filters and moderation. You can report messages you believe violate these Terms, and staff may review reported content. Our decisions regarding moderation, including removal of content and action against accounts, are final.</p>
<h2>6. Disclaimers</h2>
<p>The service is provided &ldquo;as is&rdquo; and &ldquo;as available&rdquo; without warranties of any kind, express or implied, including warranties of merchantability, fitness for a particular purpose, and non-infringement. We do not warrant that the service will be uninterrupted, secure, or error-free.</p>
<h2>7. Limitation of liability</h2>
<p>To the maximum extent permitted by law, we shall not be liable for any indirect, incidental, special, consequential, or punitive damages, or any loss of profits, data, or goodwill, arising out of or related to your use of the service. Our total liability shall not exceed the amount you paid us, if any, in the twelve (12) months preceding the claim.</p>
<h2>8. Governing law</h2>
<p>These Terms are governed by the laws of the State of Nevada, United States, without regard to conflict-of-law principles. Any dispute arising under these Terms shall be brought exclusively in the state or federal courts located in Clark County, Nevada. You consent to the personal jurisdiction of those courts.</p>
<h2>9. Changes to these Terms</h2>
<p>We may update these Terms from time to time. Continued use of the service after changes take effect constitutes acceptance of the revised Terms.</p>
<h2>10. Contact</h2>
<p>Questions about these Terms may be submitted through the in-app support ticket system.</p>";
    }

    private static function privacyBoilerplate(string $site, string $url): string
    {
        return "<h1>Privacy Policy</h1>
<p>Last updated: " . gmdate('F j, Y') . "</p>
<p>This Privacy Policy explains how <strong>$site</strong> (<code>$url</code>) collects, uses, and protects information about users. It applies to all visitors, registered users, and guests.</p>
<h2>1. Information we collect</h2>
<ul>
<li><strong>Account information:</strong> when you register, we collect a username, an email address, and a password (stored using strong, one-way hashing). We also record the date you registered and your age certification.</li>
<li><strong>Usage information:</strong> we record messages, private messages, channel memberships, reactions, and other activity needed to provide the service.</li>
<li><strong>Technical information:</strong> we record the IP address and approximate last-seen time associated with your account or guest session, and may retain log data.</li>
<li><strong>Reports and support:</strong> content you submit through message reports or support tickets, along with related account identifiers.</li>
</ul>
<h2>2. Nevada notice (NRS 597.790)</h2>
<p>Under Nevada Revised Statutes NRS 597.790, this disclosure describes the categories of personal information we collect and the purposes for which it is used. The categories are described above in Section 1. We do not sell personal information as defined under Nevada law. To exercise rights under NRS 597.790, or to request a copy of the information we hold about you, contact us through the in-app support ticket system.</p>
<h2>3. How we use information</h2>
<ul>
<li>To provide, operate, and secure the service (messaging, channels, accounts, moderation, support);</li>
<li>To enforce our Terms of Service and applicable law;</li>
<li>To investigate abuse, policy violations, and illegal activity; and</li>
<li>To communicate with you about your account (for example, support ticket replies).</li>
</ul>
<h2>4. Children&rsquo;s privacy (COPPA)</h2>
<p>The service is not directed to children, and we do not knowingly collect personal information from anyone under 13 years of age. If you believe a child under 13 has provided us personal information, contact us through the support ticket system so we can delete it. The service is further restricted to users who are at least 18 years of age.</p>
<h2>5. Parent / guardian rights (NRS 597.795)</h2>
<p>If you are a parent or legal guardian and believe we may have collected information from your minor child, you may contact us as described below. We will take reasonable steps to investigate and, where appropriate, delete such information.</p>
<h2>6. Disclosure of information</h2>
<p>We do not sell your personal information. We may share information with service providers who help operate the service, with law enforcement or regulators when required by law, or to protect the rights, property, or safety of the service, its users, or the public.</p>
<h2>7. Data retention</h2>
<p>We retain account information and chat archives for as long as needed to operate the service and comply with legal obligations. Message history and archive logs are generally retained indefinitely as an append-only record; deleting an account removes account data but may preserve archived message content as required for safety and legal purposes.</p>
<h2>8. Data security</h2>
<p>We use reasonable administrative, technical, and physical safeguards, including password hashing (argon2id), prepared database queries, and transport security, to protect your information. No method of transmission or storage is completely secure, and we cannot guarantee absolute security.</p>
<h2>9. Your choices</h2>
<p>You may change your password, avatar, and notification preferences at any time through your profile. You may request deletion of your account by contacting us; account deletion is at the discretion of the operators.</p>
<h2>10. Changes to this policy</h2>
<p>We may update this Privacy Policy from time to time. Material changes will be reflected by the &ldquo;Last updated&rdquo; date above.</p>
<h2>11. Contact</h2>
<p>Questions about this Privacy Policy may be submitted through the in-app support ticket system.</p>";
    }
}
