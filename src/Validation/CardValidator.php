<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Validation;

use Rondeto\JSContact\Conversion\Issue;
use Rondeto\JSContact\Model\Address;
use Rondeto\JSContact\Model\AddressComponent;
use Rondeto\JSContact\Model\Card;
use Rondeto\JSContact\Model\EmailAddress;
use Rondeto\JSContact\Model\Link;
use Rondeto\JSContact\Model\Name;
use Rondeto\JSContact\Model\NameComponent;
use Rondeto\JSContact\Model\Nickname;
use Rondeto\JSContact\Model\Note;
use Rondeto\JSContact\Model\OnlineService;
use Rondeto\JSContact\Model\Phone;

/**
 * Checks a Card against the rules of RFC 9553 (as updated by RFC 9982) that its PHP types
 * cannot express.
 */
final class CardValidator
{
    /** @var list<Issue> */
    private array $issues = [];

    /**
     * @return list<Issue>
     */
    public function validate(Card $card): array
    {
        $this->issues = [];

        $this->nonEmpty('/prodId', $card->prodId);
        $this->nonEmpty('/uid', $card->uid);
        $this->nonEmpty('/language', $card->language);
        $this->enum('/kind', $card->kind, Registry::CARD_KINDS);

        if ([] !== $card->members && Card::KIND_GROUP !== $card->kind) {
            $this->add('/members', 'members can only be set when kind is "group"');
        }

        foreach ($card->members as $uid) {
            $this->nonEmpty('/members/'.$this->escape($uid), $uid);
        }

        foreach ($card->keywords as $keyword) {
            $this->nonEmpty('/keywords/'.$this->escape($keyword), $keyword);
        }

        if (null !== $card->name) {
            $this->name('/name', $card->name);
        }

        $this->map('/nicknames', $card->nicknames, $this->nickname(...));
        $this->map('/emails', $card->emails, $this->email(...));
        $this->map('/phones', $card->phones, $this->phone(...));
        $this->map('/addresses', $card->addresses, $this->address(...));
        $this->map('/onlineServices', $card->onlineServices, $this->onlineService(...));
        $this->map('/links', $card->links, $this->link(...));
        $this->map('/notes', $card->notes, $this->note(...));

        $this->extra('', $card->extra, [
            '@type', 'version', 'uid', 'prodId', 'created', 'updated', 'kind', 'language', 'members', 'name',
            'nicknames', 'emails', 'phones', 'addresses', 'onlineServices', 'links', 'notes', 'keywords',
        ], Registry::UNMODELED_CARD_PROPERTIES);

        return $this->issues;
    }

    private function name(string $path, Name $name): void
    {
        if ([] === $name->components && null === $name->full) {
            $this->add($path, 'a name needs components, full, or both');
        }

        $this->nonEmpty($path.'/full', $name->full);
        $this->components($path, $name->components, $name->isOrdered, $name->defaultSeparator, Registry::NAME_COMPONENT_KINDS);
        $this->phonetic($path, $name->components, $name->phoneticSystem, $name->phoneticScript);

        if ([] !== $name->sortAs && [] === $name->components) {
            $this->add($path.'/sortAs', 'sortAs can only be set when components are');
        }

        $kinds = array_map(static fn (NameComponent $component): string => $component->kind, $name->components);
        foreach (array_keys($name->sortAs) as $kind) {
            if (!\in_array($kind, $kinds, true)) {
                $this->add($path.'/sortAs/'.$this->escape($kind), 'no name component has this kind');
            }
        }

        $this->extra($path, $name->extra, ['@type', 'components', 'isOrdered', 'defaultSeparator', 'full', 'sortAs', 'phoneticScript', 'phoneticSystem']);
    }

    private function nickname(string $path, Nickname $nickname): void
    {
        $this->nonEmpty($path.'/name', $nickname->name);
        $this->contexts($path, $nickname->contexts, Registry::CONTEXTS);
        $this->pref($path, $nickname->pref);
        $this->extra($path, $nickname->extra, ['@type', 'name', 'contexts', 'pref']);
    }

    private function email(string $path, EmailAddress $email): void
    {
        if (!Syntax::isEmailAddress($email->address)) {
            $this->add($path.'/address', \sprintf('"%s" is not an email address', $email->address));
        }

        $this->contexts($path, $email->contexts, Registry::CONTEXTS);
        $this->pref($path, $email->pref);
        $this->extra($path, $email->extra, ['@type', 'address', 'contexts', 'pref', 'label']);
    }

    private function phone(string $path, Phone $phone): void
    {
        $this->nonEmpty($path.'/number', $phone->number);
        $this->set($path.'/features', $phone->features, Registry::PHONE_FEATURES);
        $this->contexts($path, $phone->contexts, Registry::CONTEXTS);
        $this->pref($path, $phone->pref);
        $this->extra($path, $phone->extra, ['@type', 'number', 'features', 'contexts', 'pref', 'label']);
    }

    private function address(string $path, Address $address): void
    {
        if ([] === $address->components && null === $address->coordinates && null === $address->countryCode
            && null === $address->full && null === $address->timeZone) {
            $this->add($path, 'an address needs at least one of components, coordinates, countryCode, full or timeZone');
        }

        $this->components($path, $address->components, $address->isOrdered, $address->defaultSeparator, Registry::ADDRESS_COMPONENT_KINDS);
        $this->phonetic($path, $address->components, $address->phoneticSystem, $address->phoneticScript);

        if (null !== $address->countryCode && 1 !== preg_match('/^[A-Za-z]{2}$/', $address->countryCode)) {
            $this->add($path.'/countryCode', 'not an ISO 3166-1 alpha-2 code');
        }

        if (null !== $address->coordinates && !str_starts_with(strtolower($address->coordinates), 'geo:')) {
            $this->add($path.'/coordinates', 'not a "geo:" URI');
        }

        $this->nonEmpty($path.'/timeZone', $address->timeZone);
        $this->nonEmpty($path.'/full', $address->full);
        $this->contexts($path, $address->contexts, Registry::ADDRESS_CONTEXTS);
        $this->pref($path, $address->pref);
        $this->extra($path, $address->extra, [
            '@type', 'components', 'isOrdered', 'countryCode', 'coordinates', 'timeZone', 'contexts', 'full',
            'defaultSeparator', 'pref', 'phoneticScript', 'phoneticSystem',
        ]);
    }

    private function onlineService(string $path, OnlineService $service): void
    {
        if (null === $service->uri && null === $service->user) {
            $this->add($path, 'an online service needs a uri, a user, or both');
        }

        $this->uri($path.'/uri', $service->uri);
        $this->nonEmpty($path.'/service', $service->service);
        $this->contexts($path, $service->contexts, Registry::CONTEXTS);
        $this->pref($path, $service->pref);
        $this->extra($path, $service->extra, ['@type', 'service', 'uri', 'user', 'contexts', 'pref', 'label']);
    }

    private function link(string $path, Link $link): void
    {
        $this->uri($path.'/uri', $link->uri);
        $this->enum($path.'/kind', $link->kind, Registry::LINK_KINDS);
        $this->contexts($path, $link->contexts, Registry::CONTEXTS);
        $this->pref($path, $link->pref);
        $this->extra($path, $link->extra, ['@type', 'uri', 'kind', 'mediaType', 'contexts', 'pref', 'label']);
    }

    private function note(string $path, Note $note): void
    {
        if (null !== $note->author) {
            $author = $note->author;
            if (null === $author->name && null === $author->uri && [] === $author->extra) {
                $this->add($path.'/author', 'an author needs at least one property');
            }

            $this->uri($path.'/author/uri', $author->uri);
            $this->extra($path.'/author', $author->extra, ['@type', 'name', 'uri']);
        }

        $this->extra($path, $note->extra, ['@type', 'note', 'created', 'author']);
    }

    /**
     * Rules shared by Name and Address components (RFC 9553, sections 2.2.1.1 and 2.5.1.1).
     *
     * @param list<NameComponent>|list<AddressComponent> $components
     * @param list<string>                               $kinds
     */
    private function components(string $path, array $components, bool $isOrdered, ?string $defaultSeparator, array $kinds): void
    {
        $hasValue = false;
        $previousIsSeparator = false;
        foreach ($components as $index => $component) {
            $componentPath = $path.'/components/'.$index;
            $this->enum($componentPath.'/kind', $component->kind, $kinds);
            $this->extra($componentPath, $component->extra, ['@type', 'kind', 'value', 'phonetic']);

            $isSeparator = 'separator' === $component->kind;
            if ($isSeparator && !$isOrdered) {
                $this->add($componentPath, 'separators are only allowed when isOrdered is true');
            }

            if ($isSeparator && $previousIsSeparator) {
                $this->add($componentPath, 'two separators cannot follow each other');
            }

            $hasValue = $hasValue || !$isSeparator;
            $previousIsSeparator = $isSeparator;
        }

        if ([] !== $components && !$hasValue) {
            $this->add($path.'/components', 'at least one component must not be a separator');
        }

        if (null !== $defaultSeparator && (!$isOrdered || [] === $components)) {
            $this->add($path.'/defaultSeparator', 'defaultSeparator requires ordered components');
        }
    }

    /**
     * @param list<NameComponent>|list<AddressComponent> $components
     */
    private function phonetic(string $path, array $components, ?string $system, ?string $script): void
    {
        $this->enum($path.'/phoneticSystem', $system, Registry::PHONETIC_SYSTEMS);
        if (null !== $script && 1 !== preg_match('/^[A-Za-z]{4}$/', $script)) {
            $this->add($path.'/phoneticScript', 'not a script subtag');
        }

        if (null !== $system || null !== $script) {
            return;
        }

        foreach ($components as $index => $component) {
            if (null !== $component->phonetic) {
                $this->add($path.'/components/'.$index.'/phonetic', 'phonetic requires phoneticSystem or phoneticScript');
            }
        }
    }

    /**
     * @template T of object
     *
     * @param array<array-key, T>       $map
     * @param callable(string, T): void $validate
     */
    private function map(string $path, array $map, callable $validate): void
    {
        foreach ($map as $id => $value) {
            $id = (string) $id; // PHP turns numeric string keys into integers
            if (!Syntax::isId($id)) {
                $this->add($path.'/'.$this->escape($id), 'not a valid Id');
            }

            $validate($path.'/'.$this->escape($id), $value);
        }
    }

    /**
     * @param list<string> $contexts
     * @param list<string> $known
     */
    private function contexts(string $path, array $contexts, array $known): void
    {
        $this->set($path.'/contexts', $contexts, $known);
    }

    /**
     * @param list<string> $values
     * @param list<string> $known
     */
    private function set(string $path, array $values, array $known): void
    {
        foreach ($values as $value) {
            $this->enum($path.'/'.$this->escape($value), $value, $known);
        }
    }

    /**
     * Unknown values are allowed (they may be registered later, or vendor-specific), but
     * not a registered one with a different case.
     *
     * @param list<string> $known
     */
    private function enum(string $path, ?string $value, array $known): void
    {
        if (null === $value) {
            return;
        }

        if ('' === $value) {
            $this->add($path, 'must not be empty');
        } elseif (null !== ($expected = Syntax::caseVariantOf($value, $known))) {
            $this->add($path, \sprintf('"%s" must be written "%s"', $value, $expected));
        }
    }

    private function pref(string $path, ?int $pref): void
    {
        if (null !== $pref && ($pref < 1 || $pref > 100)) {
            $this->add($path.'/pref', 'must be between 1 and 100');
        }
    }

    private function uri(string $path, ?string $uri): void
    {
        if (null !== $uri && !Syntax::isUri($uri)) {
            $this->add($path, \sprintf('"%s" is not a URI', $uri));
        }
    }

    private function nonEmpty(string $path, ?string $value): void
    {
        if ('' === $value) {
            $this->add($path, 'must not be empty');
        }
    }

    /**
     * @param array<array-key, mixed> $extra
     * @param list<string>            $modeled    Properties the class models: they cannot be in $extra
     * @param list<string>            $registered Other registered properties, allowed in $extra
     */
    private function extra(string $path, array $extra, array $modeled, array $registered = Registry::UNMODELED_COMMON_PROPERTIES): void
    {
        foreach (array_keys($extra) as $name) {
            $name = (string) $name; // PHP turns numeric string keys into integers
            $propertyPath = $path.'/'.$this->escape($name);
            if (\in_array(strtolower($name), array_map(strtolower(...), $modeled), true)) {
                $this->add($propertyPath, 'this property is modeled: set it on the object, not in extra');
            } elseif ('extra' === strtolower($name)) {
                $this->add($propertyPath, '"extra" is a reserved property name');
            } elseif (null !== ($expected = Syntax::caseVariantOf($name, $registered))) {
                $this->add($propertyPath, \sprintf('must be written "%s"', $expected));
            } elseif (!Syntax::isIanaName($name) && !Syntax::isVendorExtension($name)) {
                $this->add($propertyPath, 'not a valid property name');
            }
        }
    }

    private function add(string $path, string $message): void
    {
        $this->issues[] = new Issue($path, $message);
    }

    /**
     * Escapes a JSON Pointer reference token (RFC 6901, section 4).
     */
    private function escape(string $token): string
    {
        return strtr($token, ['~' => '~0', '/' => '~1']);
    }
}
