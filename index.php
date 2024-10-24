<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Chatwoot\Enums\Event;
use Nette\Http\IRequest;
use Nette\Http\IResponse;
use Nette\Http\RequestFactory;
use Nette\Http\Response;
use OpenAi\Assistants\LabelConversion;
use Orhanerday\OpenAi\OpenAi;
use Tracy\Debugger;
use Tracy\ILogger;

function sendResponse(array $data, int $code = IResponse::S200_OK): void {
    $response = new Response();
    $response->setContentType('application/json');
    $response->setCode($code);
    die(json_encode($data));
}

function getLabelFromChatGPT(string $message): ?array
{
    $openAi = new OpenAi($_ENV['OPENAI_API_KEY']);
    $openAi->setORG($_ENV['OPENAI_ORG']);
    $openAi->setAssistantsBetaVersion('v2');
    $openAi->setTimeout(3);

    try {
        $openAiAssistantId = $_ENV['OPENAI_ASSISTANT_ID'];
        $assistant = new LabelConversion(
            $openAiAssistantId === '' ? null : $openAiAssistantId,
            ["demand", "support", "spam", "offer", "billing"]
        );
        return $assistant($openAi, $message);
    } catch (Exception $e) {
        Debugger::log($e->getMessage(), ILogger::ERROR);
        return null;
    }
}

try {
    if (file_exists(__DIR__ . DIRECTORY_SEPARATOR . '.env')) {
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
        $dotenv->safeLoad();
        $dotenv->required(
            ['OPENAI_API_KEY', 'OPENAI_ORG', 'OPENAI_ASSISTANT_ID', 'CHATWOOT_API_ACCESS_TOKEN', 'CHATWOOT_API_URL']
        );
    }

    Debugger::enable(Debugger::Development);
    Debugger::$showBar = false;

    $requestFactory = new RequestFactory();
    $request = $requestFactory->fromGlobals();

    if (!$request->isMethod(IRequest::Post) || $request->isAjax()) {
        throw new Exception('Invalid request method');
    }

    $token = $request->getQuery('token') ?? '';
    if (!is_string($token) || !is_scalar($token)) {
        throw new Exception('Authorization failed');
    } elseif ($token !== $_ENV['AUTH_TOKEN']) {
        throw new Exception('Invalid auth key');
    }

    // validate schema
    $requestBodyJson = json_decode($request->getRawBody());
    file_put_contents(__DIR__ . DIRECTORY_SEPARATOR . 'last_request', $request->getRawBody());
    try {
        $processor = new Nette\Schema\Processor;
        /** @var Chatwoot\Schemas\Events\ConversationCreated $conversationCreatedEvent */
        $conversationCreatedEvent = $processor->process(Chatwoot\Schemas\Events\ConversationCreated::getSchema(), $requestBodyJson);
    } catch (Nette\Schema\ValidationException $e) {
        throw new Exception('Could not validate a request');
    }

    $event = Event::from($conversationCreatedEvent->event);
    if ($event !== Event::ConversationCreated) {
        throw new Exception('Invalid event');
    }

    $messages = $conversationCreatedEvent->messages;
    if (empty($messages)) {
        throw new Exception('Conversation messages are empty');
    }

    $initialMessage = $messages[0];
    if ($initialMessage->content === '') {
        throw new Exception('Message content is empty');
    }

    $labels = getLabelFromChatGPT($initialMessage->content);
    if ($labels === null) {
        throw new Exception('Failed to get label from message');
    }

    $client = new \Chatwoot\Client($_ENV['CHATWOOT_API_ACCESS_TOKEN'], $_ENV['CHATWOOT_API_URL']);
    if (!$client->addConversationLabel($initialMessage->account_id, $requestBodyJson->id, $labels)) {
        throw new Exception('Failed to add label');
    }

    sendResponse([
        'status' => 'success',
        'message' => 'Label has been added',
    ]);
} catch (Exception $e) {
    sendResponse([
        'status' => 'failed',
        'message' => $e->getMessage(),
    ], IResponse::S500_InternalServerError);
}


