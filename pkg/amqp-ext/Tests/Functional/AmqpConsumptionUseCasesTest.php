<?php

namespace Enqueue\AmqpExt\Tests\Functional;

use Enqueue\AmqpExt\AmqpContext;
use Enqueue\Consumption\ChainExtension;
use Enqueue\Consumption\Extension\LimitConsumedMessagesExtension;
use Enqueue\Consumption\Extension\LimitConsumptionTimeExtension;
use Enqueue\Consumption\Extension\ReplyExtension;
use Enqueue\Consumption\QueueConsumer;
use Enqueue\Consumption\Result;
use Enqueue\Psr\Context;
use Enqueue\Psr\Message;
use Enqueue\Psr\Processor;
use Enqueue\Test\RabbitmqAmqpExtension;
use Enqueue\Test\RabbitmqManagmentExtensionTrait;

/**
 * @group functional
 */
class AmqpConsumptionUseCasesTest extends \PHPUnit_Framework_TestCase
{
    use RabbitmqAmqpExtension;
    use RabbitmqManagmentExtensionTrait;

    /**
     * @var AmqpContext
     */
    private $amqpContext;

    public function setUp()
    {
        $this->amqpContext = $this->buildAmqpContext();

        $this->removeQueue('amqp_ext.test');
    }

    public function tearDown()
    {
        $this->amqpContext->close();
    }

    public function testConsumeOneMessageAndExit()
    {
        $queue = $this->amqpContext->createQueue('amqp_ext.test');
        $this->amqpContext->declareQueue($queue);

        $message = $this->amqpContext->createMessage(__METHOD__);
        $this->amqpContext->createProducer()->send($queue, $message);

        $queueConsumer = new QueueConsumer($this->amqpContext, new ChainExtension([
            new LimitConsumedMessagesExtension(1),
            new LimitConsumptionTimeExtension(new \DateTime('+3sec')),
        ]));

        $processor = new StubProcessor();
        $queueConsumer->bind($queue, $processor);

        $queueConsumer->consume();

        $this->assertInstanceOf(Message::class, $processor->lastProcessedMessage);
        $this->assertEquals(__METHOD__, $processor->lastProcessedMessage->getBody());
    }

    public function testConsumeOneMessageAndSendReplyExit()
    {
        $queue = $this->amqpContext->createQueue('amqp_ext.test');
        $this->amqpContext->declareQueue($queue);

        $replyQueue = $this->amqpContext->createQueue('amqp_ext.test_reply');
        $this->amqpContext->declareQueue($replyQueue);

        $message = $this->amqpContext->createMessage(__METHOD__);
        $message->setReplyTo($replyQueue->getQueueName());
        $this->amqpContext->createProducer()->send($queue, $message);

        $queueConsumer = new QueueConsumer($this->amqpContext, new ChainExtension([
            new LimitConsumedMessagesExtension(2),
            new LimitConsumptionTimeExtension(new \DateTime('+3sec')),
            new ReplyExtension(),
        ]));

        $replyMessage = $this->amqpContext->createMessage(__METHOD__.'.reply');

        $processor = new StubProcessor();
        $processor->result = Result::reply($replyMessage);

        $replyProcessor = new StubProcessor();

        $queueConsumer->bind($queue, $processor);
        $queueConsumer->bind($replyQueue, $replyProcessor);
        $queueConsumer->consume();

        $this->assertInstanceOf(Message::class, $processor->lastProcessedMessage);
        $this->assertEquals(__METHOD__, $processor->lastProcessedMessage->getBody());

        $this->assertInstanceOf(Message::class, $replyProcessor->lastProcessedMessage);
        $this->assertEquals(__METHOD__.'.reply', $replyProcessor->lastProcessedMessage->getBody());
    }
}

class StubProcessor implements Processor
{
    public $result = Result::ACK;

    /** @var \Enqueue\Psr\Message */
    public $lastProcessedMessage;

    public function process(Message $message, Context $context)
    {
        $this->lastProcessedMessage = $message;

        return $this->result;
    }
}
