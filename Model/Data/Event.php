<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Model\Data;

use Aavirbhava\AdsAnalytics\Api\Data\EventInterface;
use Magento\Framework\DataObject;

class Event extends DataObject implements EventInterface
{
    public function getVisitorUuid(): string
    {
        return (string)$this->_getData(self::VISITOR_UUID);
    }

    public function setVisitorUuid(string $visitorUuid): self
    {
        return $this->setData(self::VISITOR_UUID, $visitorUuid);
    }

    public function getEventType(): string
    {
        return (string)$this->_getData(self::EVENT_TYPE);
    }

    public function setEventType(string $eventType): self
    {
        return $this->setData(self::EVENT_TYPE, $eventType);
    }

    public function getPlatformCode(): ?string
    {
        return $this->_getData(self::PLATFORM_CODE);
    }

    public function setPlatformCode(?string $platformCode): self
    {
        return $this->setData(self::PLATFORM_CODE, $platformCode);
    }

    public function getClickIdParam(): ?string
    {
        return $this->_getData(self::CLICK_ID_PARAM);
    }

    public function setClickIdParam(?string $clickIdParam): self
    {
        return $this->setData(self::CLICK_ID_PARAM, $clickIdParam);
    }

    public function getClickIdValue(): ?string
    {
        return $this->_getData(self::CLICK_ID_VALUE);
    }

    public function setClickIdValue(?string $clickIdValue): self
    {
        return $this->setData(self::CLICK_ID_VALUE, $clickIdValue);
    }

    public function getUtmSource(): ?string
    {
        return $this->_getData(self::UTM_SOURCE);
    }

    public function setUtmSource(?string $utmSource): self
    {
        return $this->setData(self::UTM_SOURCE, $utmSource);
    }

    public function getUtmMedium(): ?string
    {
        return $this->_getData(self::UTM_MEDIUM);
    }

    public function setUtmMedium(?string $utmMedium): self
    {
        return $this->setData(self::UTM_MEDIUM, $utmMedium);
    }

    public function getUtmCampaign(): ?string
    {
        return $this->_getData(self::UTM_CAMPAIGN);
    }

    public function setUtmCampaign(?string $utmCampaign): self
    {
        return $this->setData(self::UTM_CAMPAIGN, $utmCampaign);
    }

    public function getReferrer(): ?string
    {
        return $this->_getData(self::REFERRER);
    }

    public function setReferrer(?string $referrer): self
    {
        return $this->setData(self::REFERRER, $referrer);
    }

    public function getIpHash(): ?string
    {
        return $this->_getData(self::IP_HASH);
    }

    public function setIpHash(?string $ipHash): self
    {
        return $this->setData(self::IP_HASH, $ipHash);
    }

    public function getUserAgent(): ?string
    {
        return $this->_getData(self::USER_AGENT);
    }

    public function setUserAgent(?string $userAgent): self
    {
        return $this->setData(self::USER_AGENT, $userAgent);
    }

    public function getLandingPage(): ?string
    {
        return $this->_getData(self::LANDING_PAGE);
    }

    public function setLandingPage(?string $landingPage): self
    {
        return $this->setData(self::LANDING_PAGE, $landingPage);
    }

    public function getPageUrl(): ?string
    {
        return $this->_getData(self::PAGE_URL);
    }

    public function setPageUrl(?string $pageUrl): self
    {
        return $this->setData(self::PAGE_URL, $pageUrl);
    }

    public function getEntityId(): ?int
    {
        $value = $this->_getData(self::ENTITY_ID);
        return $value === null ? null : (int)$value;
    }

    public function setEntityId(?int $entityId): self
    {
        return $this->setData(self::ENTITY_ID, $entityId);
    }

    public function getTimestamp(): ?string
    {
        return $this->_getData(self::TIMESTAMP);
    }

    public function setTimestamp(?string $timestamp): self
    {
        return $this->setData(self::TIMESTAMP, $timestamp);
    }
}
