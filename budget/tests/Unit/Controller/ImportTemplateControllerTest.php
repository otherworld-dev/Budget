<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\ImportTemplateController;
use OCA\Budget\Db\ImportTemplate;
use OCA\Budget\Service\ImportTemplateService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ImportTemplateControllerTest extends TestCase {
    private ImportTemplateController $controller;
    private ImportTemplateService $service;
    private IRequest $request;
    private LoggerInterface $logger;

    protected function setUp(): void {
        $this->request = $this->createMock(IRequest::class);
        $this->service = $this->createMock(ImportTemplateService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(fn ($text, $params = []) => vsprintf($text, $params));

        $this->controller = new ImportTemplateController(
            $this->request,
            $this->service,
            $l,
            'user1',
            $this->logger
        );
    }

    private function makeTemplate(): ImportTemplate {
        $template = new ImportTemplate();
        $template->setId(1);
        $template->setUserId('user1');
        $template->setName('My Bank');
        $template->setMappingFromArray(['date' => 'Date', 'amount' => 'Amount', 'description' => 'Memo']);
        return $template;
    }

    public function testIndexReturnsTemplates(): void {
        $this->service->method('findAll')->with('user1')->willReturn([$this->makeTemplate()]);

        $response = $this->controller->index();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertCount(1, $response->getData());
    }

    public function testShowReturnsTemplate(): void {
        $this->service->method('find')->with(1, 'user1')->willReturn($this->makeTemplate());

        $response = $this->controller->show(1);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('My Bank', $response->getData()->getName());
    }

    public function testShowReturnsNotFound(): void {
        $this->service->method('find')->willThrowException(new DoesNotExistException('nope'));

        $response = $this->controller->show(999);

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }

    public function testCreateCsvReturnsCreated(): void {
        $this->service->method('create')->willReturn($this->makeTemplate());

        $response = $this->controller->create('My Bank', 'csv', ['date' => 'Date', 'amount' => 'Amount', 'description' => 'Memo']);

        $this->assertSame(Http::STATUS_CREATED, $response->getStatus());
    }

    public function testCreateOfxRoutingReturnsCreated(): void {
        $this->service->expects($this->once())
            ->method('create')
            ->with('user1', 'OFX Bank', 'ofx', [], ['1234' => 5], ',', false, true, false, null)
            ->willReturn($this->makeTemplate());

        $response = $this->controller->create('OFX Bank', 'ofx', [], ['1234' => 5]);

        $this->assertSame(Http::STATUS_CREATED, $response->getStatus());
    }

    public function testCreateReturnsValidationError(): void {
        $this->service->method('create')
            ->willThrowException(new \InvalidArgumentException('A date column mapping is required'));

        $response = $this->controller->create('Bank', 'csv', ['amount' => 'Amount']);

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('A date column mapping is required', $response->getData()['error']);
    }

    public function testIndexFiltersByFormat(): void {
        $this->service->expects($this->once())
            ->method('findAllByFormat')
            ->with('user1', 'ofx')
            ->willReturn([$this->makeTemplate()]);

        $response = $this->controller->index('ofx');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }

    public function testUpdateReturnsUpdated(): void {
        $this->service->method('update')->willReturn($this->makeTemplate());

        $response = $this->controller->update(1, 'Renamed');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }

    public function testUpdateWithNoFieldsReturnsBadRequest(): void {
        $response = $this->controller->update(1);

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('No valid fields to update', $response->getData()['error']);
    }

    public function testUpdateReturnsValidationError(): void {
        $this->service->method('update')
            ->willThrowException(new \InvalidArgumentException('A template with this name already exists'));

        $response = $this->controller->update(1, 'Dupe');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('A template with this name already exists', $response->getData()['error']);
    }

    public function testDestroyReturnsSuccess(): void {
        $this->service->expects($this->once())->method('delete')->with(1, 'user1');

        $response = $this->controller->destroy(1);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('success', $response->getData()['status']);
    }

    public function testDestroyReturnsNotFound(): void {
        $this->service->method('delete')->willThrowException(new DoesNotExistException('nope'));

        $response = $this->controller->destroy(999);

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }
}
