# CHANGELOG

## 2.6.0

-   [BC BREAK] The `assets/` directory was moved from `Resources/assets/` to `assets/`. Make
    sure the path in your `package.json` file is updated accordingly.

-   The directory structure of the bundle was updated to match modern best-practices.

## 2.3

-   The `Broadcast` attribute can now be repeated, this is convenient to render several Turbo Streams Twig templates for the same change

## 2.2

-   The topics defined in the `Broadcast` attribute now support expression language when prefixed with `@=`.

## 2.1

-   `TurboStreamResponse` and `AddTurboStreamFormatSubscriber` have been removed, use native content negotiation instead:

    ```php
    use Symfony\UX\Turbo\TurboBundle;

    class TaskController extends AbstractController
    {
        public function new(Request $request): Response
        {
            // ...
            if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
                $request->setRequestFormat(TurboBundle::STREAM_FORMAT);
                $response = $this->render('task/success.stream.html.twig', ['task' => $task]);
            } else {
                $response = $this->render('task/success.html.twig', ['task' => $task]);
            }

            return $response->setVary('Accept');
        }
    }
    ```

## 2.0

-   Support for `stimulus` version 2 was removed and support for `@hotwired/stimulus`
    version 3 was added. See the [@symfony/stimulus-bridge CHANGELOG](https://github.com/symfony/stimulus-bridge/blob/main/CHANGELOG.md#300)
    for more details.
-   Support added for Symfony 6
-   `@hotwired/turbo` version bumped to stable 7.0.

## 1.3

-   Package introduced! The new `symfony/ux-turbo` and `symfony/ux-turbo-mercure`
    were introduced.
