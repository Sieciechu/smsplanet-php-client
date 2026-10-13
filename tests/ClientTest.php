<?php

declare(strict_types=1);

namespace SMSPLANET\PHP\Tests;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use SMSPLANET\PHP\Client;
use SMSPLANET\PHP\Client\Exceptions\InvalidParameterException;
use SMSPLANET\PHP\Client\Exceptions\MissingParameterException;
use SMSPLANET\PHP\Client\Exceptions\RequestException;

class ClientTest extends TestCase
{
    private const SUCCESS_RESPONSE = '<?xml version="1.0" encoding="UTF-8"?><response><result>OK</result><messageId>12345</messageId></response>';

    /** @var array<int, array{request: RequestInterface}> */
    private array $history = [];

    private function createSut(Response ...$responses): Client
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new Client(
            ['key' => 'api-key', 'password' => 'api-pass'],
            new HttpClient(['base_uri' => 'https://api.smsplanet.pl', 'handler' => $stack])
        );
    }

    private function lastRequest(): RequestInterface
    {
        return $this->history[array_key_last($this->history)]['request'];
    }

    /**
     * @return array<string, string>
     */
    private function lastRequestFormParams(): array
    {
        parse_str((string) $this->lastRequest()->getBody(), $params);

        return $params;
    }

    public function testSendSimpleSMS_whenMessageAccepted_thenReturnsMessageIdAndSendsCredentials(): void
    {
        // given
        $sut = $this->createSut(new Response(200, [], self::SUCCESS_RESPONSE));

        // when
        $messageId = $sut->sendSimpleSMS(['from' => 'EPOM', 'to' => '48111222333', 'msg' => 'Hello']);

        // then
        $this->assertSame(12345, $messageId);
        $request = $this->lastRequest();
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/send', $request->getUri()->getPath());
        parse_str($request->getUri()->getQuery(), $query);
        $this->assertSame(
            ['key' => 'api-key', 'password' => 'api-pass', 'from' => 'EPOM', 'msg' => 'Hello', 'to' => '48111222333'],
            $query
        );
    }

    public function testSendSimpleSMS_whenApiReturnsError_thenThrowsRequestExceptionWithApiCode(): void
    {
        // given
        $sut = $this->createSut(new Response(
            200,
            [],
            '<?xml version="1.0" encoding="UTF-8"?><response><result>ERROR</result><errorCode>102</errorCode><errorMsg>Invalid credentials</errorMsg></response>'
        ));

        // then
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('Invalid credentials');
        $this->expectExceptionCode(102);

        // when
        $sut->sendSimpleSMS(['from' => 'EPOM', 'to' => '48111222333', 'msg' => 'Hello']);
    }

    /**
     * @return array<string, array{array<string, string>, class-string<\Throwable>}>
     */
    public static function invalidParamsProvider(): array
    {
        return [
            'missing message' => [['from' => 'EPOM', 'to' => '48111222333'], MissingParameterException::class],
            'missing recipient' => [['from' => 'EPOM', 'msg' => 'Hello'], MissingParameterException::class],
            'unknown parameter' => [['from' => 'EPOM', 'to' => '48111222333', 'msg' => 'Hello', 'foo' => 'bar'], InvalidParameterException::class],
        ];
    }

    /**
     * @param array<string, string> $args
     * @param class-string<\Throwable> $expectedException
     */
    #[DataProvider('invalidParamsProvider')]
    public function testSendSimpleSMS_whenParamsInvalid_thenThrowsWithoutCallingApi(array $args, string $expectedException): void
    {
        // given
        $sut = $this->createSut();

        // then
        $this->expectException($expectedException);

        // when
        $sut->sendSimpleSMS($args);
    }

    public function testSendSMS_whenManyRecipients_thenEachRecipientIsSeparateToElement(): void
    {
        // given
        $sut = $this->createSut(new Response(200, [], self::SUCCESS_RESPONSE));

        // when
        $messageId = $sut->sendSMS(['from' => 'EPOM', 'to' => ['48111222333', '48444555666'], 'msg' => 'Hello']);

        // then
        $this->assertSame(12345, $messageId);
        $this->assertSame('POST', $this->lastRequest()->getMethod());
        $xml = $this->lastRequestFormParams()['xmldata'];
        $this->assertStringContainsString('<to>48111222333</to><to>48444555666</to>', $xml);
        $this->assertStringContainsString('<key>api-key</key>', $xml);
        $this->assertStringContainsString('<msg>Hello</msg>', $xml);
    }

    public function testSendMMS_whenAttachmentGiven_thenAttachmentMimeTypeIsDetected(): void
    {
        // given
        $sut = $this->createSut(new Response(200, [], self::SUCCESS_RESPONSE));

        // when
        $messageId = $sut->sendMMS([
            'from' => 'EPOM',
            'to' => '48111222333',
            'msg' => 'Hello',
            'attachments' => 'https://example.com/plane.jpg',
        ]);

        // then
        $this->assertSame(12345, $messageId);
        $xml = $this->lastRequestFormParams()['xmldata'];
        $this->assertStringContainsString(
            '<att nr="1" link="https://example.com/plane.jpg" mimetype="image/jpeg"/>',
            $xml
        );
    }

    public function testGetSenderFields_whenApiReturnsJson_thenReturnsDecodedData(): void
    {
        // given
        $sut = $this->createSut(new Response(200, [], '["EPOM","TEST"]'));

        // when
        $fields = $sut->getSenderFields();

        // then
        $this->assertSame(['EPOM', 'TEST'], $fields);
        $this->assertSame('/senderFields', $this->lastRequest()->getUri()->getPath());
        $this->assertSame(
            ['key' => 'api-key', 'password' => 'api-pass', 'product' => 'SMS'],
            $this->lastRequestFormParams()
        );
    }

    public function testGetBalance_whenApiReturnsNumber_thenReturnsInteger(): void
    {
        // given
        $sut = $this->createSut(new Response(200, [], '250'));

        // when
        $balance = $sut->getBalance();

        // then
        $this->assertSame(250, $balance);
        $this->assertSame('/getBalance', $this->lastRequest()->getUri()->getPath());
    }

    public function testCancelMessage_whenMessageCancelled_thenSendsMessageId(): void
    {
        // given
        $sut = $this->createSut(new Response(200, [], '<?xml version="1.0" encoding="UTF-8"?><response><result>OK</result></response>'));

        // when
        $result = $sut->cancelMessage(12345);

        // then
        $this->assertSame('OK', (string) $result->result);
        $this->assertSame('/cancelMessage', $this->lastRequest()->getUri()->getPath());
        $this->assertSame('12345', $this->lastRequestFormParams()['messageId']);
    }
}
