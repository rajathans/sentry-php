<?php

declare(strict_types=1);

namespace Sentry;

use Http\Client\Common\Plugin;
use Http\Client\Common\Plugin\AuthenticationPlugin;
use Http\Client\Common\Plugin\BaseUriPlugin;
use Http\Client\Common\Plugin\DecoderPlugin;
use Http\Client\Common\Plugin\ErrorPlugin;
use Http\Client\Common\Plugin\HeaderSetPlugin;
use Http\Client\Common\Plugin\RetryPlugin;
use Http\Client\Common\PluginClient;
use Http\Client\HttpAsyncClient;
use Http\Discovery\HttpAsyncClientDiscovery;
use Http\Discovery\MessageFactoryDiscovery;
use Http\Discovery\UriFactoryDiscovery;
use Http\Message\MessageFactory;
use Http\Message\UriFactory;
use Jean85\PrettyVersions;
use Sentry\HttpClient\Authentication\SentryAuth;
use Sentry\Integration\ErrorHandlerIntegration;
use Sentry\Integration\RequestIntegration;
use Sentry\Serializer\RepresentationSerializer;
use Sentry\Serializer\RepresentationSerializerInterface;
use Sentry\Serializer\Serializer;
use Sentry\Serializer\SerializerInterface;
use Sentry\Transport\HttpTransport;
use Sentry\Transport\NullTransport;
use Sentry\Transport\TransportInterface;

/**
 * The default implementation of {@link ClientBuilderInterface}.
 *
 * @author Stefano Arlandini <sarlandini@alice.it>
 */
final class ClientBuilder implements ClientBuilderInterface
{
    /**
     * @var Options The client options
     */
    private $options;

    /**
     * @var UriFactory|null The PSR-7 URI factory
     */
    private $uriFactory;

    /**
     * @var MessageFactory|null The PSR-7 message factory
     */
    private $messageFactory;

    /**
     * @var TransportInterface The transport
     */
    private $transport;

    /**
     * @var HttpAsyncClient|null The HTTP client
     */
    private $httpClient;

    /**
     * @var Plugin[] The list of Httplug plugins
     */
    private $httpClientPlugins = [];

    /**
     * @var SerializerInterface The serializer to be injected in the client
     */
    private $serializer;

    /**
     * @var RepresentationSerializerInterface The representation serializer to be injected in the client
     */
    private $representationSerializer;

    /**
     * @var string The SDK identifier, to be used in {@see Event} and {@see SentryAuth}
     */
    private $sdkIdentifier = Client::SDK_IDENTIFIER;

    /**
     * @var string The SDK version of the Client
     */
    private $sdkVersion;

    /**
     * Class constructor.
     *
     * @param Options $options The client options
     */
    public function __construct(Options $options = null)
    {
        $this->options = $options ?? new Options();

        if ($this->options->hasDefaultIntegrations()) {
            $this->options->setIntegrations(\array_merge([
                new ErrorHandlerIntegration(),
                new RequestIntegration($this->options),
            ], $this->options->getIntegrations()));
        }
    }

    /**
     * {@inheritdoc}
     */
    public static function create(Options $options = null): self
    {
        return new static($options);
    }

    /**
     * {@inheritdoc}
     */
    public function getOptions(): Options
    {
        return $this->options;
    }

    /**
     * {@inheritdoc}
     */
    public function setUriFactory(UriFactory $uriFactory): self
    {
        $this->uriFactory = $uriFactory;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function setMessageFactory(MessageFactory $messageFactory): self
    {
        $this->messageFactory = $messageFactory;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function setTransport(TransportInterface $transport): self
    {
        $this->transport = $transport;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function setHttpClient(HttpAsyncClient $httpClient): self
    {
        $this->httpClient = $httpClient;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function addHttpClientPlugin(Plugin $plugin): self
    {
        $this->httpClientPlugins[] = $plugin;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function removeHttpClientPlugin(string $className): self
    {
        foreach ($this->httpClientPlugins as $index => $httpClientPlugin) {
            if (!$httpClientPlugin instanceof $className) {
                continue;
            }

            unset($this->httpClientPlugins[$index]);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function setSerializer(SerializerInterface $serializer): self
    {
        $this->serializer = $serializer;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function setRepresentationSerializer(RepresentationSerializerInterface $representationSerializer): self
    {
        $this->representationSerializer = $representationSerializer;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function setSdkIdentifier(string $sdkIdentifier): self
    {
        $this->sdkIdentifier = $sdkIdentifier;

        return $this;
    }

    /**
     * Gets the SDK version to be passed onto {@see Event} and HTTP User-Agent header.
     *
     * @return string
     */
    private function getSdkVersion(): string
    {
        if (null === $this->sdkVersion) {
            $this->setSdkVersionByPackageName('sentry/sentry');
        }

        return $this->sdkVersion;
    }

    /**
     * {@inheritdoc}
     */
    public function setSdkVersion(string $sdkVersion): self
    {
        $this->sdkVersion = $sdkVersion;

        return $this;
    }

    /**
     * Sets the version of the SDK package that generated this Event using the Packagist name.
     *
     * @param string $packageName The package name that will be used to get the version from (i.e. "sentry/sentry")
     *
     * @return $this
     */
    public function setSdkVersionByPackageName(string $packageName): self
    {
        $this->sdkVersion = PrettyVersions::getVersion($packageName)->getPrettyVersion();

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getClient(): ClientInterface
    {
        $this->messageFactory = $this->messageFactory ?? MessageFactoryDiscovery::find();
        $this->uriFactory = $this->uriFactory ?? UriFactoryDiscovery::find();
        $this->httpClient = $this->httpClient ?? HttpAsyncClientDiscovery::find();
        $this->transport = $this->transport ?? $this->createTransportInstance();

        return new Client($this->options, $this->transport, $this->createEventFactory());
    }

    /**
     * Creates a new instance of the HTTP client.
     *
     * @return PluginClient
     */
    private function createHttpClientInstance(): PluginClient
    {
        if (null === $this->uriFactory) {
            throw new \RuntimeException('The PSR-7 URI factory must be set.');
        }

        if (null === $this->httpClient) {
            throw new \RuntimeException('The PSR-18 HTTP client must be set.');
        }

        if (null !== $this->options->getDsn()) {
            $this->addHttpClientPlugin(new BaseUriPlugin($this->uriFactory->createUri($this->options->getDsn())));
        }

        $this->addHttpClientPlugin(new HeaderSetPlugin(['User-Agent' => $this->sdkIdentifier . '/' . $this->getSdkVersion()]));
        $this->addHttpClientPlugin(new AuthenticationPlugin(new SentryAuth($this->options, $this->sdkIdentifier, $this->getSdkVersion())));
        $this->addHttpClientPlugin(new RetryPlugin(['retries' => $this->options->getSendAttempts()]));
        $this->addHttpClientPlugin(new ErrorPlugin());

        if ($this->options->isCompressionEnabled()) {
            $this->addHttpClientPlugin(new DecoderPlugin());
        }

        return new PluginClient($this->httpClient, $this->httpClientPlugins);
    }

    /**
     * Creates a new instance of the transport mechanism.
     *
     * @return TransportInterface
     */
    private function createTransportInstance(): TransportInterface
    {
        if (null !== $this->transport) {
            return $this->transport;
        }

        if (null === $this->options->getDsn()) {
            return new NullTransport();
        }

        if (null === $this->messageFactory) {
            throw new \RuntimeException('The PSR-7 message factory must be set.');
        }

        return new HttpTransport($this->options, $this->createHttpClientInstance(), $this->messageFactory);
    }

    /**
     * Instantiate the {@see EventFactory} with the configured serializers.
     *
     * @return EventFactoryInterface
     */
    private function createEventFactory(): EventFactoryInterface
    {
        $this->serializer = $this->serializer ?? new Serializer();
        $this->representationSerializer = $this->representationSerializer ?? new RepresentationSerializer();

        return new EventFactory($this->serializer, $this->representationSerializer, $this->options, $this->sdkIdentifier, $this->getSdkVersion());
    }
}
