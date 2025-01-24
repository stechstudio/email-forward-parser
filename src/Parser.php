<?php

namespace STS\EmailForward;

use Exception;

class Parser
{
    protected array $regexes = [];

    public function __construct()
    {
        $this->initRegexes();
    }

    public function read($body, $subject = null)
    {
        $subjectParsed = null;
        $email = [];
        $result = [];

        if ($subject) {
            $subjectParsed = $this->parseSubject($subject);
        }

        $forwarded = ($subject && $subjectParsed !== null) ? true : false;

        if (!$subject || $forwarded) {
            $result = $this->parseBody($body, $forwarded);

            if ($result['email']) {
                $forwarded = true;
                $email = $this->parseOriginalEmail($result['email'], $result['body']);
            }
        }

        return [
            'forwarded' => $forwarded,
            'message'   => $result['message'] ?? null,
            'email'     => [
                'body'    => $email['body'] ?? null,
                'from'    => [
                    'address' => $email['from']['address'] ?? null,
                    'name'    => $email['from']['name'] ?? null
                ],
                'to'      => $email['to'] ?? null,
                'cc'      => $email['cc'] ?? null,
                'subject' => $subjectParsed ?? $email['subject'] ?? null,
                'date'    => $email['date'] ?? null
            ]
        ];
    }

    private function initRegexes()
    {
        foreach (self::REGEXES as $key => $entry) {
            $keyLine = $key.'_line';
            $this->regexes[$key] = [];
            $this->regexes[$keyLine] = [];

            if (is_array($entry)) {
                foreach ($entry as $regex) {
                    if (in_array($key, self::LINE_REGEXES)) {
                        $regexLine = $this->buildLineRegex($regex);
                        $this->regexes[$keyLine][] = $regexLine;
                    }
                    $this->regexes[$key][] = $regex;
                }
            } else {
                if (in_array($key, self::LINE_REGEXES)) {
                    $regexLine = $this->buildLineRegex($entry);
                    $this->regexes[$keyLine] = $regexLine;
                }
                $this->regexes[$key] = $entry;
            }
        }
    }

    private function buildLineRegex($regex)
    {
        $delimiter = '/'; // We know the delimiter is '/'
        $lastDelimiterPos = strrpos($regex, $delimiter); // Find the last '/'

        if ($lastDelimiterPos === false) {
            throw new Exception("Invalid regex: No closing delimiter found.");
        }

        // Extract the pattern (between the first and last '/')
        $pattern = substr($regex, 1, $lastDelimiterPos - 1);

        // Extract the modifiers (after the last '/')
        $modifiers = substr($regex, $lastDelimiterPos + 1);

        // Wrap the pattern in a capturing group
        $modifiedPattern = '('.$pattern.')';

        // Reassemble the regex with the modified pattern and original modifiers
        return $delimiter.$modifiedPattern.$delimiter.$modifiers;
    }

    public function parseSubject($subject)
    {
        $match = $this->loopRegexes($this->regexes['subject'], $subject);
        if ($match && count($match) > 1) {
            return trim($match[1]) ?: "";
        }
        return null;
    }

    public function parseBody($body, $forwarded = false)
    {
        // Replace carriage return by regular line break
        $body = preg_replace($this->regexes['carriage_return'], "\n", $body);
        // Remove Byte Order Mark
        $body = preg_replace($this->regexes['byte_order_mark'], "", $body);
        // Remove trailing Non-breaking space
        $body = preg_replace($this->regexes['trailing_non_breaking_space'], "", $body);
        // Replace Non-breaking space with regular space
        $body = preg_replace($this->regexes['non_breaking_space'], " ", $body);

        // First method: split via the separator (Apple Mail, Gmail, Outlook Live / 365,
        // Outlook 2019, Yahoo Mail, Thunderbird)
        // Notice: use 'line' regex that will capture the line itself, as we may
        // need it to build the original email back (in case of nested emails)
        $match = $this->loopRegexes($this->regexes['separator_line'], $body, "split");

        if ($match && count($match) > 2) {
            // The `split` operation creates a match with 3 substrings:
            //  * 0: anything before the line with the separator (i.e. the message)
            //  * 1: the line with the separator
            //  * 2: anything after the line with the separator (i.e. the body of \
            //       the original email)
            // Notice: in case of nested emails, there may be several matches \
            //   against 'separator_line'. In that case, the `split` operation \
            //   creates a match with (n x 3) substrings. We need to reconciliate \
            //   those substrings.
            $email = $this->reconciliateSplitMatch($match, 3, [2]);

            return [
                'body'    => $body,
                'message' => $match[0] ? trim($match[0]) : null,
                'email'   => trim($email)
            ];
        }

        // Attempt second method?
        // Notice: as this second method is more uncertain (we split via the From \
        //   part, without further verification), we have to be sure we can \
        //   attempt it. The `forwarded` boolean gives the confirmation that the \
        //   email was indeed forwarded (detected from the Subject part)
        if ($forwarded) {
            // Second method: split via the From part (New Outlook 2019, Outlook Live / 365)
            $match = $this->loopRegexes($this->regexes['original_from'], $body, "split");
            if ($match && count((array) $match) > 3) {
                $email = $this->reconciliateSplitMatch($match, 4, [1, 3], function ($i) {
                    return ($i % 3 === 2);
                });

                return [
                    'body'    => $body,
                    'message' => $match[0] ? trim($match[0]) : null,
                    'email'   => trim($email)
                ];
            }
        }

        return [];
    }

    public function parseOriginalEmail($text, $body)
    {
        // Remove Byte Order Mark
        $text = preg_replace($this->regexes['byte_order_mark'], "", $text);
        // Remove ">" at the beginning of each line, while keeping line breaks
        $text = preg_replace($this->regexes['quote_line_break'], "", $text);
        // Remove ">" at the beginning of other lines
        $text = preg_replace($this->regexes['quote'], "", $text);
        // Remove "    " at the beginning of lines
        $text = preg_replace($this->regexes['four_spaces'], "", $text);

        return [
            'body'    => $this->parseOriginalBody($text),
            'from'    => $this->parseOriginalFrom($text, $body),
            'to'      => $this->parseOriginalTo($text),
            'cc'      => $this->parseOriginalCc($text),
            'subject' => $this->parseOriginalSubject($text),
            'date'    => $this->parseOriginalDate($text, $body)
        ];
    }

    private function parseOriginalBody($text)
    {
        $regexes = [
            $this->regexes['original_subject_line'],
            $this->regexes['original_cc_line'],
            $this->regexes['original_to_line'],
            $this->regexes['original_reply_to_line'],
            $this->regexes['original_date_line']
        ];

        foreach ($regexes as $regex) {
            $match = $this->loopRegexes($regex, $text, "split");
            if ($match && count($match) > 2 && strpos($match[3], "\n\n") === 0) {
                $body = $this->reconciliateSplitMatch($match, 4, [3], function ($i) {
                    return ($i % 3 === 2);
                });
                return trim($body);
            }
        }

        $match = $this->loopRegexes(array_merge(
            $this->regexes['original_subject_line'],
            $this->regexes['original_subject_lax_line']
        ), $text, "split");

        if ($match && count($match) > 3) {
            $body = $this->reconciliateSplitMatch($match, 4, [3], function ($i) {
                return ($i % 3 === 2);
            });
            return trim($body);
        }

        return $text;
    }

    private function parseOriginalFrom($text, $body)
    {
        $address = null;
        $name = null;

        // First method: extract the author via the From part (Apple Mail, Gmail,
        // Outlook Live / 365, New Outlook 2019, Thunderbird)
        $author = $this->parseMailbox($this->regexes['original_from'], $text);

        // Author found?
        if (($author['address'] ?? null) || ($author['name'] ?? null)) {
            return $author;
        }

        // Multiple authors found?
        if (is_array($author) && ($author[0]['address'] ?? null)) {
            return $author[0];
        }

        // Second method: extract the author via the separator (Outlook 2019)
        $match = $this->loopRegexes($this->regexes['separator_with_information'], $body);

        if ($match && count($match) > 4 && isset($match['from_address'])) {
            // Notice: the order of parts may change depending on the localization,
            // hence the use of named groups
            return $this->prepareMailbox($match['from_address'], $match['from_name']);
        }

        // Third method: extract the author via the From part, using lax regexes
        // (Yahoo Mail)
        $match = $this->loopRegexes($this->regexes['original_from_lax'], $text);
        if ($match && count($match) > 1) {
            return $this->prepareMailbox($match[3], $match[2]);
        }

        return $this->prepareMailbox(null, null);
    }

    private function parseOriginalTo($text)
    {
        // First method: extract the primary recipient(s) via the To part
        // (Apple Mail, Gmail, Outlook Live / 365, New Outlook 2019, Thunderbird)
        $recipients = $this->parseMailbox($this->regexes['original_to'], $text, true);

        // Recipient(s) found?
        if (is_array($recipients) && count($recipients) > 0) {
            return $recipients;
        }

        // Second method: the Subject, Date and Cc parts are stuck to the To part,
        // remove them before attempting a new extract, using lax regexes
        // (Yahoo Mail)
        $cleanText = $this->loopRegexes($this->regexes['original_subject_lax'], $text, "replace");
        $cleanText = $this->loopRegexes($this->regexes['original_date_lax'], $cleanText, "replace");
        $cleanText = $this->loopRegexes($this->regexes['original_cc_lax'], $cleanText, "replace");

        return $this->parseMailbox($this->regexes['original_to_lax'], $cleanText, true);
    }

    private function parseOriginalCc($text)
    {
        // First method: extract the carbon-copy recipient(s) via the Cc part
        //(Apple Mail, Gmail, Outlook Live / 365, New Outlook 2019, Thunderbird)
        $recipients = $this->parseMailbox($this->regexes['original_cc'], $text, true);

        // Recipient(s) found?
        if (is_array($recipients) && count($recipients) > 0) {
            return $recipients;
        }

        // Second method: the Subject and Date parts are stuck to the To part,
        // remove them before attempting a new extract, using lax regexes
        // (Yahoo Mail)
        $cleanText = $this->loopRegexes($this->regexes['original_subject_lax'], $text, "replace");
        $cleanText = $this->loopRegexes($this->regexes['original_date_lax'], $cleanText, "replace");

        return $this->parseMailbox($this->regexes['original_cc_lax'], $cleanText, true);
    }

    private function parseMailbox($regexes, $text, $forceArray = false)
    {
        $match = $this->loopRegexes($regexes, $text);

        if ($match && count($match) > 0) {
            $mailboxesLine = trim($match[count($match) - 1] ?? "");

            if ($mailboxesLine) {
                $mailboxes = [];
                while ($mailboxesLine) {
                    $mailboxMatch = $this->loopRegexes($this->regexes['mailbox'], $mailboxesLine);

                    // Address and / or name available?
                    if ($mailboxMatch && count($mailboxMatch) > 0) {
                        $address = null;
                        $name = null;

                        if (count($mailboxMatch) === 3) {
                            $name = $mailboxMatch[1];
                            $address = $mailboxMatch[2];
                        } else {
                            $address = $mailboxMatch[1];
                        }

                        $mailboxes[] = $this->prepareMailbox($address, $name);
                        $mailboxesLine = trim(str_replace($mailboxMatch[0], "", $mailboxesLine));

                        if ($mailboxesLine) {
                            foreach (self::MAILBOXES_SEPARATORS as $separator) {
                                if ($mailboxesLine[0] === $separator) {
                                    $mailboxesLine = trim(substr($mailboxesLine, 1));
                                    break;
                                }
                            }
                        }
                    } else {
                        $mailboxes[] = $this->prepareMailbox($mailboxesLine, null);
                        $mailboxesLine = "";
                    }
                }

                if (count($mailboxes) > 1) {
                    return $mailboxes;
                }

                return $forceArray ? $mailboxes : ($mailboxes[0] ?? null);
            }
        }

        return $forceArray ? [] : null;
    }

    private function parseOriginalSubject($text)
    {
        $match = $this->loopRegexes($this->regexes['original_subject'], $text);
        if ($match && count($match) > 0) {
            return trim($match[1]);
        }

        $match = $this->loopRegexes($this->regexes['original_subject_lax'], $text);
        if ($match && count($match) > 0) {
            return trim($match[1]);
        }

        return null;
    }

    private function parseOriginalDate($text, $body)
    {
        // First method: extract the date via the Date part (Apple Mail, Gmail,
        // Outlook Live / 365, New Outlook 2019, Thunderbird)
        $match = $this->loopRegexes($this->regexes['original_date'], $text);
        if ($match && count($match) > 0) {
            return trim($match[1]);
        }

        // Second method: extract the date via the separator (Outlook 2019)
        $match = $this->loopRegexes($this->regexes['separator_with_information'], $body);
        if ($match && count($match) > 4 && isset($match['date'])) {
            // Notice: the order of parts may change depending on the localization, \
            // hence the use of named groups
            return trim($match['date']);
        }

        // Third method: the Subject part is stuck to the Date part, remove it
        // before attempting a new extract, using lax regexes (Yahoo Mail)
        $cleanText = $this->loopRegexes($this->regexes['original_subject_lax'], $text, "replace");
        $match = $this->loopRegexes($this->regexes['original_date_lax'], $cleanText);

        if ($match && count($match) > 0) {
            return trim($match[1]);
        }

        return null;
    }

    private function prepareMailbox($address, $name)
    {
        $address = $address ? trim($address) : null;
        $name = $name ? trim($name) : null;

        // Make sure mailbox address is valid
        $mailboxAddressMatch = $this->loopRegexes($this->regexes['mailbox_address'], $address);

        // Invalid mailbox address? Some clients only include the name
        if (empty($mailboxAddressMatch)) {
            $name = $address;
            $address = null;
        }

        return [
            'address' => $address,
            // Some clients fill the name with the address
            // ("bessie.berry@acme.com <bessie.berry@acme.com>")
            'name'    => ($address !== $name) ? $name : null
        ];
    }

    private function loopRegexes($regexes, $str, $mode = "match", $highestPosition = true)
    {
        $minLength = $mode === "split" ? 1 : 0;
        $maxLength = strlen($str);

        foreach ($regexes as $regex) {
            $currentMatch = [];
            if ($mode === "replace") {
                $currentMatch = preg_replace($regex, "", $str);
                if (strlen($currentMatch) <= $maxLength) {
                    $match = $currentMatch;
                    break;
                }
            } else {
                if ($mode === "split" || $mode === "match") {
                    if ($mode === "split") {
                        $currentMatch = preg_split($regex, $str, -1, PREG_SPLIT_DELIM_CAPTURE);
                    } else {
                        preg_match($regex, $str, $currentMatch);
                    }

                    if (count((array) $currentMatch) > $minLength) {
                        if ($highestPosition) {
                            if (!$match) {
                                $match = $currentMatch;
                            } else {
                                $higher = false;
                                if ($mode === "match") {
                                    $higher = $match['index'] > $currentMatch['index'];
                                } else {
                                    if ($mode === "split") {
                                        $higher = strlen($match[0]) > strlen($currentMatch[0]);
                                    }
                                }

                                if ($higher) {
                                    $match = $currentMatch;
                                }
                            }
                        } else {
                            $match = $currentMatch;
                            break;
                        }
                    }
                }
            }
        }

        return $mode === "replace"
            ? ($match ?? "")
            : ($match ?? []);
    }

    private function reconciliateSplitMatch($match, $minSubstrings, $defaultSubstrings, $fnExclude = null)
    {
        $str = "";
        foreach ($defaultSubstrings as $index) {
            $str .= $match[$index];
        }

        if (count($match) > $minSubstrings) {
            for ($i = $minSubstrings, $iMax = count($match); $i < $iMax; $i++) {
                $exclude = false;
                if (is_callable($fnExclude)) {
                    $exclude = $fnExclude($i);
                }
                if (!$exclude) {
                    $str .= $match[$i];
                }
            }
        }

        return $str;
    }

    const MAILBOXES_SEPARATORS = [",", ";"];

    const LINE_REGEXES = [
        "separator", "original_subject", "original_subject_lax", "original_to",
        "original_reply_to", "original_cc", "original_date"
    ];

    const REGEXES = [
        'quote_line_break'            => '/^(>+)\s?$/m', // Apple Mail, Missive
        'quote'                       => '/^(>+)\s?/m', // Apple Mail
        'four_spaces'                 => '/^(\ {4})\s?/m', // Outlook 2019
        'carriage_return'             => '/\r\n/m', // Outlook 2019
        'byte_order_mark'             => '/\x{FEFF}/um', // Outlook 2019 (note the use of \x and u modifier)
        'trailing_non_breaking_space' => '/\x{A0}$/um', // IONOS by 1 & 1 (note the use of \x and u modifier)
        'non_breaking_space'          => '/\x{A0}/um', // (note the use of \x and u modifier)
        'subject'                     => [
            '/^Fw:(.*)/m', '/^VS:(.*)/m', '/^WG:(.*)/m', '/^RV:(.*)/m',
            '/^TR:(.*)/m', '/^I:(.*)/m', '/^FW:(.*)/m', '/^Vs:(.*)/m',
            '/^PD:(.*)/m', '/^ENC:(.*)/m', '/^Redir.:(.*)/m', '/^VB:(.*)/m',
            '/^VL:(.*)/m', '/^Videresend:(.*)/m', '/^İLT:(.*)/m', '/^Fwd:(.*)/m'
        ],
        'separator'                   => [
            '/^>?\s*Begin forwarded message\s?:/mu', // Apple Mail (en)
            '/^>?\s*Začátek přeposílané zprávy\s?:/mu', // Apple Mail (cs)
            '/^>?\s*Start på videresendt besked\s?:/mu', // Apple Mail (da)
            '/^>?\s*Anfang der weitergeleiteten Nachricht\s?:/mu', // Apple Mail (de)
            '/^>?\s*Inicio del mensaje reenviado\s?:/mu', // Apple Mail (es)
            '/^>?\s*Välitetty viesti alkaa\s?:/mu', // Apple Mail (fi)
            '/^>?\s*Début du message réexpédié\s?:/mu', // Apple Mail (fr)
            '/^>?\s*Début du message transféré\s?:/mu', // Apple Mail iOS (fr)
            '/^>?\s*Započni proslijeđenu poruku\s?:/mu', // Apple Mail (hr)
            '/^>?\s*Továbbított levél kezdete\s?:/mu', // Apple Mail (hu)
            '/^>?\s*Inizio messaggio inoltrato\s?:/mu', // Apple Mail (it)
            '/^>?\s*Begin doorgestuurd bericht\s?:/mu', // Apple Mail (nl)
            '/^>?\s*Videresendt melding\s?:/mu', // Apple Mail (no)
            '/^>?\s*Początek przekazywanej wiadomości\s?:/mu', // Apple Mail (pl)
            '/^>?\s*Início da mensagem reencaminhada\s?:/mu', // Apple Mail (pt)
            '/^>?\s*Início da mensagem encaminhada\s?:/mu', // Apple Mail (pt-br)
            '/^>?\s*Începe mesajul redirecționat\s?:/mu', // Apple Mail (ro)
            '/^>?\s*Начало переадресованного сообщения\s?:/mu', // Apple Mail (ru)
            '/^>?\s*Začiatok preposlanej správy\s?:/mu', // Apple Mail (sk)
            '/^>?\s*Vidarebefordrat mejl\s?:/mu', // Apple Mail (sv)
            '/^>?\s*İleti başlangıcı\s?:/mu', // Apple Mail (tr)
            '/^>?\s*Початок листа, що пересилається\s?:/mu', // Apple Mail (uk)
            '/^\s*-{8,10}\s*Forwarded message\s*-{8,10}\s*/m', // Gmail (all locales), Missive (en), HubSpot (en)
            '/^\s*_{32}\s*$/m', // Outlook Live / 365 (all locales)
            '/^\s?Forwarded message:/m', // Mailmate
            '/^\s?Dne\s?.+\,\s?.+\s*[\[|<].+[\]|>]\s?napsal\(a\)\s?:/mu', // Outlook 2019 (cz)
            '/^\s?D.\s?.+\s?skrev\s?\".+\"\s*[\[|<].+[\]|>]\s?:/mu', // Outlook 2019 (da)
            '/^\s?Am\s?.+\s?schrieb\s?\".+\"\s*[\[|<].+[\]|>]\s?:/mu', // Outlook 2019 (de)
            '/^\s?On\s?.+\,\s?\".+\"\s*[\[|<].+[\]|>]\s?wrote\s?:/m', // Outlook 2019 (en)
            '/^\s?El\s?.+\,\s?\".+\"\s*[\[|<].+[\]|>]\s?escribió\s?:/mu', // Outlook 2019 (es)
            '/^\s?Le\s?.+\,\s?«.+»\s*[\[|<].+[\]|>]\s?a écrit\s?:/mu', // Outlook 2019 (fr)
            '/^\s?.+\s*[\[|<].+[\]|>]\s?kirjoitti\s?.+\s?:/mu', // Outlook 2019 (fi)
            '/^\s?.+\s?időpontban\s?.+\s*[\[|<|(].+[\]|>|)]\s?ezt írta\s?:/mu', // Outlook 2019 (hu)
            '/^\s?Il giorno\s?.+\s?\".+\"\s*[\[|<].+[\]|>]\s?ha scritto\s?:/mu', // Outlook 2019 (it)
            '/^\s?Op\s?.+\s?heeft\s?.+\s*[\[|<].+[\]|>]\s?geschreven\s?:/mu', // Outlook 2019 (nl)
            '/^\s?.+\s*[\[|<].+[\]|>]\s?skrev følgende den\s?.+\s?:/mu', // Outlook 2019 (no)
            '/^\s?Dnia\s?.+\s?„.+”\s*[\[|<].+[\]|>]\s?napisał\s?:/mu', // Outlook 2019 (pl)
            '/^\s?Em\s?.+\,\s?\".+\"\s*[\[|<].+[\]|>]\s?escreveu\s?:/mu', // Outlook 2019 (pt)
            '/^\s?.+\s?пользователь\s?\".+\"\s*[\[|<].+[\]|>]\s?написал\s?:/mu', // Outlook 2019 (ru)
            '/^\s?.+\s?používateľ\s?.+\s*\([\[|<].+[\]|>]\)\s?napísal\s?:/mu', // Outlook 2019 (sk)
            '/^\s?Den\s?.+\s?skrev\s?\".+\"\s*[\[|<].+[\]|>]\s?följande\s?:/mu', // Outlook 2019 (sv)
            '/^\s?\".+\"\s*[\[|<].+[\]|>]\,\s?.+\s?tarihinde şunu yazdı\s?:/mu', // Outlook 2019 (tr)
            '/^\s*-{5,8} Přeposlaná zpráva -{5,8}\s*/mu', // Yahoo Mail (cs), Thunderbird (cs)
            '/^\s*-{5,8} Videresendt meddelelse -{5,8}\s*/mu', // Yahoo Mail (da), Thunderbird (da)
            '/^\s*-{5,10} Weitergeleitete Nachricht -{5,10}\s*/mu', // Yahoo Mail (de), Thunderbird (de), HubSpot (de)
            '/^\s*-{5,8} Forwarded Message -{5,8}\s*/m', // Yahoo Mail (en), Thunderbird (en)
            '/^\s*-{5,10} Mensaje reenviado -{5,10}\s*/mu', // Yahoo Mail (es), Thunderbird (es), HubSpot (es)
            '/^\s*-{5,10} Edelleenlähetetty viesti -{5,10}\s*/mu', // Yahoo Mail (fi), HubSpot (fi)
            '/^\s*-{5} Message transmis -{5}\s*/mu', // Yahoo Mail (fr)
            '/^\s*-{5,8} Továbbított üzenet -{5,8}\s*/mu', // Yahoo Mail (hu), Thunderbird (hu)
            '/^\s*-{5,10} Messaggio inoltrato -{5,10}\s*/mu', // Yahoo Mail (it), HubSpot (it)
            '/^\s*-{5,10} Doorgestuurd bericht -{5,10}\s*/mu', // Yahoo Mail (nl), Thunderbird (nl), HubSpot (nl)
            '/^\s*-{5,8} Videresendt melding -{5,8}\s*/mu', // Yahoo Mail (no), Thunderbird (no)
            '/^\s*-{5} Przekazana wiadomość -{5}\s*/mu', // Yahoo Mail (pl)
            '/^\s*-{5,8} Mensagem reencaminhada -{5,8}\s*/mu', // Yahoo Mail (pt), Thunderbird (pt)
            '/^\s*-{5,10} Mensagem encaminhada -{5,10}\s*/mu', // Yahoo Mail (pt-br), Thunderbird (pt-br), HubSpot (pt-br)
            '/^\s*-{5,8} Mesaj redirecționat -{5,8}\s*/mu', // Yahoo Mail (ro)
            '/^\s*-{5} Пересылаемое сообщение -{5}\s*/mu', // Yahoo Mail (ru)
            '/^\s*-{5} Preposlaná správa -{5}\s*/mu', // Yahoo Mail (sk)
            '/^\s*-{5,10} Vidarebefordrat meddelande -{5,10}\s*/mu', // Yahoo Mail (sv), Thunderbird (sv), HubSpot (sv)
            '/^\s*-{5} İletilmiş Mesaj -{5}\s*/mu', // Yahoo Mail (tr)
            '/^\s*-{5} Перенаправлене повідомлення -{5}\s*/mu', // Yahoo Mail (uk)
            '/^\s*-{8} Välitetty viesti \/ Fwd.Msg -{8}\s*/mu', // Thunderbird (fi)
            '/^\s*-{8,10} Message transféré -{8,10}\s*/mu', // Thunderbird (fr), HubSpot (fr)
            '/^\s*-{8} Proslijeđena poruka -{8}\s*/mu', // Thunderbird (hr)
            '/^\s*-{8} Messaggio Inoltrato -{8}\s*/mu', // Thunderbird (it)
            '/^\s*-{3} Treść przekazanej wiadomości -{3}\s*/mu', // Thunderbird (pl)
            '/^\s*-{8} Перенаправленное сообщение -{8}\s*/mu', // Thunderbird (ru)
            '/^\s*-{8} Preposlaná správa --- Forwarded Message -{8}\s*/mu', // Thunderbird (sk)
            '/^\s*-{8} İletilen İleti -{8}\s*/mu', // Thunderbird (tr)
            '/^\s*-{8} Переслане повідомлення -{8}\s*/mu', // Thunderbird (uk)
            '/^\s*-{9,10} メッセージを転送 -{9,10}\s*/mu', // HubSpot (ja)
            '/^\s*-{9,10} Wiadomość przesłana dalej -{9,10}\s*/mu', // HubSpot (pl)
            '/^>?\s*-{10} Original Message -{10}\s*/m' // IONOS by 1 & 1 (en)
        ],
        'separator_with_information'  => [
            '/^\s?Dne\s?(?<date>.+)\,\s?(?<from_name>.+)\s*[\[|<](?<from_address>.+)[\]|>]\s?napsal\(a\)\s?:/m',
            '/^\s?D.\s?(?<date>.+)\s?skrev\s?\"(?<from_name>.+)\"\s*[\[|<](?<from_address>.+)[\]|>]\s?:/m',
            '/^\s?Am\s?(?<date>.+)\s?schrieb\s?\"(?<from_name>.+)\"\s*[\[|<](?<from_address>.+)[\]|>]\s?:/m',
            '/^\s?On\s?(?<date>.+)\,\s?\"(?<from_name>.+)\"\s*[\[|<](?<from_address>.+)[\]|>]\s?wrote\s?:/m',
            '/^\s?El\s?(?<date>.+)\,\s?\"(?<from_name>.+)\"\s*[\[|<](?<from_address>.+)[\]|>]\s?escribió\s?:/m',
            '/^\s?Le\s?(?<date>.+)\,\s?«(?<from_name>.+)»\s*[\[|<](?<from_address>.+)[\]|>]\s?a écrit\s?:/m',
            '/^\s?(?<from_name>.+)\s*[\[|<](?<from_address>.+)[\]|>]\s?kirjoitti\s?(?<date>.+)\s?:/m',
            '/^\s?(?<date>.+)\s?időpontban\s?(?<from_name>.+)\s*[\[|<|(](?<from_address>.+)[\]|>|)]\s?ezt írta\s?:/m',
            '/^\s?Il giorno\s?(?<date>.+)\s?\"(?<from_name>.+)\"\s*[\[|<](?<from_address>.+)[\]|>]\s?ha scritto\s?:/m',
            '/^\s?Op\s?(?<date>.+)\s?heeft\s?(?<from_name>.+)\s*[\[|<](?<from_address>.+)[\]|>]\s?geschreven\s?:/m',
            '/^\s?(?<from_name>.+)\s*[\[|<](?<from_address>.+)[\]|>]\s?skrev følgende den\s?(?<date>.+)\s?:/m',
            '/^\s?Dnia\s?(?<date>.+)\s?„(?<from_name>.+)”\s*[\[|<](?<from_address>.+)[\]|>]\s?napisał\s?:/m',
            '/^\s?Em\s?(?<date>.+)\,\s?\"(?<from_name>.+)\"\s*[\[|<](?<from_address>.+)[\]|>]\s?escreveu\s?:/m',
            '/^\s?(?<date>.+)\s?пользователь\s?\"(?<from_name>.+)\"\s*[\[|<](?<from_address>.+)[\]|>]\s?написал\s?:/m',
            '/^\s?(?<date>.+)\s?používateľ\s?(?<from_name>.+)\s*\([\[|<](?<from_address>.+)[\]|>]\)\s?napísal\s?:/m',
            '/^\s?Den\s?(?<date>.+)\s?skrev\s?\"(?<from_name>.+)\"\s*[\[|<](?<from_address>.+)[\]|>]\s?följande\s?:/m',
            '/^\s?\"(?<from_name>.+)\"\s*[\[|<](?<from_address>.+)[\]|>]\,\s?(?<date>.+)\s?tarihinde şunu yazdı\s?:/m'
        ],
        'original_subject'            => [
            '/^\*?Subject\s?:\*?(.+)/im', '/^Předmět\s?:(.+)/im', '/^Emne\s?:(.+)/im',
            '/^Betreff\s?:(.+)/im', '/^Asunto\s?:(.+)/im', '/^Aihe\s?:(.+)/im',
            '/^Objet\s?:(.+)/im', '/^Predmet\s?:(.+)/im', '/^Tárgy\s?:(.+)/im',
            '/^Oggetto\s?:(.+)/im', '/^Onderwerp\s?:(.+)/im', '/^Temat\s?:(.+)/im',
            '/^Assunto\s?:(.+)/im', '/^Subiectul\s?:(.+)/im', '/^Тема\s?:(.+)/im',
            '/^Ämne\s?:(.+)/im', '/^Konu\s?:(.+)/im', '/^Sujet\s?:(.+)/im',
            '/^Naslov\s?:(.+)/im', '/^件名：(.+)/im'
        ],
        'original_subject_lax'        => [
            '/Subject\s?:(.+)/i', '/Emne\s?:(.+)/i', '/Předmět\s?:(.+)/i',
            '/Betreff\s?:(.+)/i', '/Asunto\s?:(.+)/i', '/Aihe\s?:(.+)/i',
            '/Objet\s?:(.+)/i', '/Tárgy\s?:(.+)/i', '/Oggetto\s?:(.+)/i',
            '/Onderwerp\s?:(.+)/i', '/Assunto\s?:?(.+)/i', '/Temat\s?:(.+)/i',
            '/Subiect\s?:(.+)/i', '/Тема\s?:(.+)/i', '/Predmet\s?:(.+)/i',
            '/Ämne\s?:(.+)/i', '/Konu\s?:(.+)/i'
        ],
        'original_from'               => [
            '/^(\*?\s*From\s?:\*?(.+))$/m', '/^(\s*Od\s?:(.+))$/m', '/^(\s*Fra\s?:(.+))$/m',
            '/^(\s*Von\s?:(.+))$/m', '/^(\s*De\s?:(.+))$/m', '/^(\s*Lähettäjä\s?:(.+))$/m',
            '/^(\s*Šalje\s?:(.+))$/m', '/^(\s*Feladó\s?:(.+))$/m', '/^(\s*Da\s?:(.+))$/m',
            '/^(\s*Van\s?:(.+))$/m', '/^(\s*Expeditorul\s?:(.+))$/m', '/^(\s*Отправитель\s?:(.+))$/m',
            '/^(\s*Från\s?:(.+))$/m', '/^(\s*Kimden\s?:(.+))$/m', '/^(\s*Від кого\s?:(.+))$/m',
            '/^(\s*Saatja\s?:(.+))$/m', '/^(\s*De la\s?:(.+))$/m', '/^(\s*Gönderen\s?:(.+))$/m',
            '/^(\s*От\s?:(.+))$/m', '/^(\s*Від\s?:(.+))$/m', '/^(\s*Mittente\s?:(.+))$/m',
            '/^(\s*Nadawca\s?:(.+))$/m', '/^(\s*de la\s?:(.+))$/m', '/^(\s*送信元：(.+))$/m'
        ],
        'original_from_lax'           => [
            '/(\s*From\s?:(.+?)\s?\n?\s*[\[|<](.+?)[\]|>])/', '/(\s*Od\s?:(.+?)\s?\n?\s*[\[|<](.+?)[\]|>])/',
            '/(\s*Fra\s?:(.+?)\s?\n?\s*[\[|<](.+?)[\]|>])/', '/(\s*Von\s?:(.+?)\s?\n?\s*[\[|<](.+?)[\]|>])/',
            '/(\s*De\s?:(.+?)\s?\n?\s*[\[|<](.+?)[\]|>])/', '/(\s*Lähettäjä\s?:(.+?)\s?\n?\s*[\[|<](.+?)[\]|>])/',
            '/(\s*Feladó\s?:(.+?)\s?\n?\s*[\[|<](.+?)[\]|>])/', '/(\s*Da\s?:(.+?)\s?\n?\s*[\[|<](.+?)[\]|>])/',
            '/(\s*Van\s?:(.+?)\s?\n?\s*[\[|<](.+?)[\]|>])/', '/(\s*De la\s?:(.+?)\s?\n?\s*[\[|<](.+?)[\]|>])/',
            '/(\s*От\s?:(.+?)\s?\n?\s*[\[|<](.+?)[\]|>])/', '/(\s*Från\s?:(.+?)\s?\n?\s*[\[|<](.+?)[\]|>])/',
            '/(\s*Kimden\s?:(.+?)\s?\n?\s*[\[|<](.+?)[\]|>])/', '/(\s*Від\s?:(.+?)\s?\n?\s*[\[|<](.+?)[\]|>])/'
        ],
        'original_to'                 => [
            '/^\*?\s*To\s?:\*?(.+)$/m', '/^\s*Komu\s?:(.+)$/m', '/^\s*Til\s?:(.+)$/m',
            '/^\s*An\s?:(.+)$/m', '/^\s*Para\s?:(.+)$/m', '/^\s*Vastaanottaja\s?:(.+)$/m',
            '/^\s*À\s?:(.+)$/m', '/^\s*Prima\s?:(.+)$/m', '/^\s*Címzett\s?:(.+)$/m',
            '/^\s*A\s?:(.+)$/m', '/^\s*Aan\s?:(.+)$/m', '/^\s*Do\s?:(.+)$/m',
            '/^\s*Destinatarul\s?:(.+)$/m', '/^\s*Кому\s?:(.+)$/m', '/^\s*Pre\s?:(.+)$/m',
            '/^\s*Till\s?:(.+)$/m', '/^\s*Kime\s?:(.+)$/m', '/^\s*Pour\s?:(.+)$/m',
            '/^\s*Adresat\s?:(.+)$/m', '/^\s*送信先：(.+)$/m'
        ],
        'original_to_lax'             => [
            '/\s*To\s?:(.+)$/m', '/\s*Komu\s?:(.+)$/m', '/\s*Til\s?:(.+)$/m',
            '/\s*An\s?:(.+)$/m', '/\s*Para\s?:(.+)$/m', '/\s*Vastaanottaja\s?:(.+)$/m',
            '/\s*À\s?:(.+)$/m', '/\s*Címzett\s?:(.+)$/m', '/\s*A\s?:(.+)$/m',
            '/\s*Aan\s?:(.+)$/m', '/\s*Do\s?:(.+)$/m', '/\s*Către\s?:(.+)$/m',
            '/\s*Кому\s?:(.+)$/m', '/\s*Till\s?:(.+)$/m', '/\s*Kime\s?:(.+)$/m'
        ],
        'original_reply_to'           => [
            '/^\s*Reply-To\s?:(.+)$/m', '/^\s*Odgovori na\s?:(.+)$/m', '/^\s*Odpověď na\s?:(.+)$/m',
            '/^\s*Svar til\s?:(.+)$/m', '/^\s*Antwoord aan\s?:(.+)$/m', '/^\s*Vastaus\s?:(.+)$/m',
            '/^\s*Répondre à\s?:(.+)$/m', '/^\s*Antwort an\s?:(.+)$/m', '/^\s*Válaszcím\s?:(.+)$/m',
            '/^\s*Rispondi a\s?:(.+)$/m', '/^\s*Svar til\s?:(.+)$/m', '/^\s*Odpowiedź-do\s?:(.+)$/m',
            '/^\s*Responder A\s?:(.+)$/m', '/^\s*Responder a\s?:(.+)$/m', '/^\s*Răspuns către\s?:(.+)$/m',
            '/^\s*Ответ-Кому\s?:(.+)$/m', '/^\s*Odpovedať-Pre\s?:(.+)$/m', '/^\s*Svara till\s?:(.+)$/m',
            '/^\s*Yanıt Adresi\s?:(.+)$/m', '/^\s*Кому відповісти\s?:(.+)$/m'
        ],
        'original_cc'                 => [
            '/^\*?\s*Cc\s?:\*?(.+)$/m', '/^\s*CC\s?:(.+)$/m', '/^\s*Kopie\s?:(.+)$/m',
            '/^\s*Kopio\s?:(.+)$/m', '/^\s*Másolat\s?:(.+)$/m', '/^\s*Kopi\s?:(.+)$/m',
            '/^\s*Dw\s?:(.+)$/m', '/^\s*Копия\s?:(.+)$/m', '/^\s*Kopia\s?:(.+)$/m',
            '/^\s*Bilgi\s?:(.+)$/m', '/^\s*Копія\s?:(.+)$/m', '/^\s*Másolatot kap\s?:(.+)$/m',
            '/^\s*Kópia\s?:(.+)$/m', '/^\s*DW\s?:(.+)$/m', '/^\s*Kopie \(CC\)\s?:(.+)$/m',
            '/^\s*Copie à\s?:(.+)$/m', '/^\s*CC：(.+)$/m'
        ],
        'original_cc_lax'             => [
            '/\s*Cc\s?:(.+)$/m', '/\s*CC\s?:(.+)$/m', '/\s*Kopie\s?:(.+)$/m',
            '/\s*Kopio\s?:(.+)$/m', '/\s*Másolat\s?:(.+)$/m', '/\s*Kopi\s?:(.+)$/m',
            '/\s*Dw\s?(.+)$/m', '/\s*Копия\s?:(.+)$/m', '/\s*Kópia\s?:(.+)$/m',
            '/\s*Kopia\s?:(.+)$/m', '/\s*Копія\s?:(.+)$/m'
        ],
        'original_date'               => [
            '/^\s*Date\s?:(.+)$/m', '/^\s*Datum\s?:(.+)$/m', '/^\s*Dato\s?:(.+)$/m',
            '/^\s*Envoyé\s?:(.+)$/m', '/^\s*Fecha\s?:(.+)$/m', '/^\s*Päivämäärä\s?:(.+)$/m',
            '/^\s*Dátum\s?:(.+)$/m', '/^\s*Data\s?:(.+)$/m', '/^\s*Dată\s?:(.+)$/m',
            '/^\s*Дата\s?:(.+)$/m', '/^\s*Tarih\s?:(.+)$/m', '/^\*?\s*Sent\s?:\*?(.+)$/m',
            '/^\s*Päiväys\s?:(.+)$/m', '/^\s*日付：(.+)$/m'
        ],
        'original_date_lax'           => [
            '/\s*Datum\s?:(.+)$/m', '/\s*Sendt\s?:(.+)$/m', '/\s*Gesendet\s?:(.+)$/m',
            '/\s*Sent\s?:(.+)$/m', '/\s*Enviado\s?:(.+)$/m', '/\s*Envoyé\s?:(.+)$/m',
            '/\s*Lähetetty\s?:(.+)$/m', '/\s*Elküldve\s?:(.+)$/m', '/\s*Inviato\s?:(.+)$/m',
            '/\s*Verzonden\s?:(.+)$/m', '/\s*Wysłano\s?:(.+)$/m', '/\s*Trimis\s?:(.+)$/m',
            '/\s*Отправлено\s?:(.+)$/m', '/\s*Odoslané\s?:(.+)$/m', '/\s*Skickat\s?:(.+)$/m',
            '/\s*Gönderilen\s?:(.+)$/m', '/\s*Відправлено\s?:(.+)$/m'
        ],
        'mailbox'                     => [
            '/^\s?\n?\s*<.+?<mailto\:(.+?)>>/', '/^(.+?)\s?\n?\s*<.+?<mailto\:(.+?)>>/',
            '/^(.+?)\s?\n?\s*[\[|<]mailto\:(.+?)[\]|>]/', '/^\'(.+?)\'\s?\n?\s*[\[|<](.+?)[\]|>]/',
            '/^\"\'(.+?)\'\"\s?\n?\s*[\[|<](.+?)[\]|>]/', '/^\"(.+?)\"\s?\n?\s*[\[|<](.+?)[\]|>]/',
            '/^([^,;]+?)\s?\n?\s*[\[|<](.+?)[\]|>]/', '/^(.?)\s?\n?\s*[\[|<](.+?)[\]|>]/',
            '/^([^\s@]+@[^\s@]+\.[^\s@,]+)/', '/^([^;].+?)\s?\n?\s*[\[|<](.+?)[\]|>]/'
        ],
        'mailbox_address'             => [
            '/^(([^\s@]+)@([^\s@]+)\.([^\s@]+))$/'
        ]
    ];
}