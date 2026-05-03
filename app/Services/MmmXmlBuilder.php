<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Str;

class MmmXmlBuilder
{
    public function buildPunchoutSetupRequest(array $payload): string
    {
        $payloadId = $payload['payload_id'] ?? Str::uuid()->toString();
        $timestamp = $payload['timestamp'] ?? Carbon::now()->toIso8601String();
        $deploymentMode = $payload['deployment_mode'] ?? 'test';
        $language = $payload['language'] ?? 'en';

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $cxml = $dom->createElement('cXML');
        $cxml->setAttribute('payloadID', $payloadId);
        $cxml->setAttribute('timestamp', $timestamp);
        $cxml->setAttribute('version', '1.0');
        $cxml->setAttribute('xml:lang', $language);

        $dom->appendChild($cxml);

        $header = $dom->createElement('Header');
        $cxml->appendChild($header);

        $from = $dom->createElement('From');
        $header->appendChild($from);
        $fromCredential = $dom->createElement('Credential');
        $fromCredential->setAttribute('domain', 'DUNS');
        $from->appendChild($fromCredential);
        $fromCredential->appendChild($dom->createElement('Identity', $payload['duns'] ?? ''));

        $to = $dom->createElement('To');
        $header->appendChild($to);
        $toCredential = $dom->createElement('Credential');
        $toCredential->setAttribute('domain', 'DUNS');
        $to->appendChild($toCredential);
        $toCredential->appendChild($dom->createElement('Identity', $payload['to_identity'] ?? ''));

        $sender = $dom->createElement('Sender');
        $header->appendChild($sender);
        $senderCredential = $dom->createElement('Credential');
        $senderCredential->setAttribute('domain', 'NetworkUserId');
        $sender->appendChild($senderCredential);
        $senderCredential->appendChild($dom->createElement('Identity', $payload['sender_identity'] ?? ''));
        $senderCredential->appendChild($dom->createElement('SharedSecret', $payload['shared_secret'] ?? ''));
        $sender->appendChild($dom->createElement('UserAgent', $payload['user_agent'] ?? ''));

        $request = $dom->createElement('Request');
        $request->setAttribute('deploymentMode', $deploymentMode);
        $cxml->appendChild($request);

        $punchout = $dom->createElement('PunchOutSetupRequest');
        $punchout->setAttribute('operation', 'create');
        $request->appendChild($punchout);

        $punchout->appendChild($dom->createElement('BuyerCookie', $payload['buyer_cookie'] ?? ''));

        $extrinsics = [
            'CostCenter' => $payload['cost_center_number'] ?? '',
            'UserEmail' => $payload['employee_email'] ?? '',
            'UniqueName' => $payload['username'] ?? '',
            'FirstName' => $payload['first_name'] ?? '',
            'LastName' => $payload['last_name'] ?? '',
            'StartPoint' => $payload['start_point'] ?? '',
        ];

        foreach ($extrinsics as $name => $value) {
            $extrinsic = $dom->createElement('Extrinsic');
            $extrinsic->setAttribute('name', $name);
            $extrinsic->appendChild($dom->createTextNode((string) $value));
            $punchout->appendChild($extrinsic);
        }

        $property = is_array($payload['property'] ?? null) ? $payload['property'] : [];
        $pictures = is_array($property['pictures'] ?? null)
            ? $property['pictures']
            : (is_array($payload['pictures'] ?? null) ? $payload['pictures'] : []);

        if ($this->propertyHasContent($property, $pictures)) {
            $properties = $dom->createElement('Properties');
            $propertyNode = $dom->createElement('Property');

            $this->appendRequiredTextElement($dom, $propertyNode, 'ID', $property['id'] ?? null);
            $this->appendRequiredTextElement($dom, $propertyNode, 'Price', $property['price'] ?? null);
            $this->appendRequiredTextElement($dom, $propertyNode, 'Address', $property['address'] ?? $payload['address'] ?? null);
            $this->appendRequiredTextElement($dom, $propertyNode, 'City', $property['city'] ?? null);
            $this->appendRequiredTextElement($dom, $propertyNode, 'State', $property['state'] ?? null);
            $this->appendRequiredTextElement($dom, $propertyNode, 'Zip', $property['zip'] ?? null);
            $this->appendRequiredTextElement($dom, $propertyNode, 'Description', $property['description'] ?? null);

            if ($pictures !== []) {
                $picturesNode = $dom->createElement('Pictures');

                foreach ($pictures as $picture) {
                    if (!is_array($picture)) {
                        continue;
                    }

                    $pictureNode = $dom->createElement('Picture');

                    $this->appendRequiredTextElement($dom, $pictureNode, 'ID', $picture['id'] ?? null);
                    $this->appendRequiredTextElement($dom, $pictureNode, 'Caption', $picture['caption'] ?? null);
                    $this->appendRequiredTextElement($dom, $pictureNode, 'FileName', $picture['filename'] ?? null);
                    $this->appendRequiredTextElement($dom, $pictureNode, 'URL', $picture['url'] ?? null);

                    if ($pictureNode->hasChildNodes()) {
                        $picturesNode->appendChild($pictureNode);
                    }
                }

                if ($picturesNode->hasChildNodes()) {
                    $propertyNode->appendChild($picturesNode);
                }
            }

            if ($propertyNode->hasChildNodes()) {
                $properties->appendChild($propertyNode);
                $punchout->appendChild($properties);
            }
        }

        $body = $dom->saveXML($dom->documentElement, LIBXML_NOEMPTYTAG);
        if ($body === false) {
            return '';
        }

        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n{$body}";
    }

    private function propertyHasContent(array $property, array $pictures): bool
    {
        foreach (['id', 'price', 'address', 'city', 'state', 'zip', 'description'] as $key) {
            if (trim((string) ($property[$key] ?? '')) !== '') {
                return true;
            }
        }

        return $pictures !== [];
    }

    private function appendTextElement(\DOMDocument $dom, \DOMElement $parent, string $name, mixed $value): void
    {
        $text = trim((string) $value);
        if ($text === '') {
            return;
        }

        $element = $dom->createElement($name);
        $element->appendChild($dom->createTextNode($text));
        $parent->appendChild($element);
    }

    private function appendRequiredTextElement(\DOMDocument $dom, \DOMElement $parent, string $name, mixed $value): void
    {
        $element = $dom->createElement($name);
        $element->appendChild($dom->createTextNode(trim((string) $value)));
        $parent->appendChild($element);
    }

    public function parsePunchoutSetupResponse(string $xml): array
    {
        $result = [
            'success' => false,
            'redirect_url' => null,
            'status_code' => null,
            'status_text' => null,
        ];

        try {
            $dom = new \DOMDocument();
            $dom->loadXML($xml);
            $xpath = new \DOMXPath($dom);

            $status = $dom->getElementsByTagName('Status')->item(0);
            if ($status) {
                $result['status_code'] = $status->getAttribute('code') ?: null;
                $result['status_text'] = $status->getAttribute('text') ?: null;
            }

            $urlNode = $xpath->query('/*[local-name()="cXML"]/*[local-name()="Response"]/*[local-name()="PunchOutSetupResponse"]/*[local-name()="StartPage"]/*[local-name()="URL"]')->item(0)
                ?: $dom->getElementsByTagName('URL')->item(0);
            if ($urlNode) {
                $result['redirect_url'] = trim($urlNode->textContent);
            }

            $result['success'] = ($result['status_code'] === '200') && !empty($result['redirect_url']);
        } catch (\Exception $e) {
            $result['status_text'] = $e->getMessage();
        }

        return $result;
    }
}
