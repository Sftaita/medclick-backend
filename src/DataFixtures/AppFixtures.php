<?php

namespace App\DataFixtures;

use Faker\Factory;
use App\Entity\User;
use App\Entity\Year;
use App\Entity\Years;
use App\Entity\Surgery;
use App\Entity\Surgeries;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;

class AppFixtures extends Fixture
{
    /**
     * Password encoder
     *
     * @var UserPasswordEncoderInterface
     */
    private $encoder;
    
    public function __construct(UserPasswordEncoderInterface $encoder){
        $this->encoder = $encoder;
    }


    public function load(ObjectManager $manager)
    {
        
        $faker = Factory::create('fr_FR');

        for ($u=0; $u<10; $u++){
            
            $user = new User;

            $hash = $this->encoder->encodePassword($user, "password");

            $user-> setFirstName($faker->firstName())
                    ->setLastName($faker->lastName())
                    ->setEmail($faker->email())
                    ->setPassword($hash);

                    for($c=0; $c < 6; $c++){
                        $year = new Years();
                        $year->setYearOfFormation($faker->randomElement(['1','2','3','4','5','6']))
                            ->setDateOfStart($faker->dateTimeBetween('- 6 years'))
                            ->setHospital($faker->randomElement(['UCL','Mont-Godinne','Jolimont','Saint-Michel','Saint-Jean']))
                            ->setMaster($faker->lastName)
                            ->setUser($user);
                        $manager->persist($year);
            
            
                            
                            for ($s=0; $s<30; $s++){
                                
            
                                $surgery = new Surgeries();
                                $surgery->setDate($faker->dateTimeBetween('- 6 years'))
                                        ->setSpeciality('Orthopedie')
                                        ->setName($faker->randomElement(['LCA','PTE','PTH','Suture coiffe des rotateurs','Osteosynthèse du tibia']))
                                        ->setPosition($faker->randomElement(['1','2']))
                                        ->setYear($year)
                                        ;
                                $manager->persist($surgery);
                                
                            }
                        }          
            $manager->persist($user);
            
            
        }

        

        $manager->flush();
        // $product = new Product();
        // $manager->persist($product);

        $manager->flush();
    }
}
