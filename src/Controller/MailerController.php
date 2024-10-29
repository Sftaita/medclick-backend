<?php

namespace App\Controller;


use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Twig\Environment;


class MailerController extends AbstractController
{
    /**
     * @var MailerInterface
     */
    private $mailer;


    /**
     * @var Environment
     */
    private $twig;

    public function __construct(MailerInterface $mailer, Environment $twig)
    {
        $this->mailer = $mailer;
        $this->twig = $twig;
    }

    /**
     * Envoie d'email
     *
     * @return void
     * 
     * @Route("/mail", name="email")
     */
    public function sendEmail(string $to, string $subject, string $template, array $parameters)
    {

        $email = (new Email())
            ->from('info@medclick.be')
            ->to($to)
            //->cc('cc@example.com')
            //->bcc('bcc@example.com')
            //->replyTo('fabien@example.com')
            //->priority(Email::PRIORITY_HIGH)
            ->subject($subject)
            ->html(
                $this->twig->render($template, $parameters)
            );

        $this->mailer->send($email);
    }
}
