<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ClimaticProfile;
use App\Entity\EnergyProvider;
use App\Entity\FGasRule;
use App\Entity\Fluid;
use App\Entity\Printer3d;
use App\Entity\Refrigerant;
use App\Enum\FluidState;
use App\Repository\ClimaticProfileRepository;
use App\Repository\EnergyProviderRepository;
use App\Repository\FGasRuleRepository;
use App\Repository\FluidRepository;
use App\Repository\Printer3dRepository;
use App\Repository\RefrigerantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Charge les données de référence de la calculatrice : fluides, imprimantes 3D, offres
 * d'électricité (config/calculator/reference_data.yaml), fluides frigorigènes
 * (config/calculator/refrigerants.json), règles F-Gas (config/calculator/fgas_rules.json) et profils
 * de l'enceinte climatique (config/calculator/climatic_profiles.json). Les fichiers JSON sont aussi lus
 * par assets/calculators (données par défaut et tests).
 *
 * N'ajoute que ce qui manque (repéré par le nom du fluide, la marque et le modèle de
 * l'imprimante, le fournisseur et l'offre, le code du fluide frigorigène, de la règle ou du profil) :
 * une valeur modifiée dans l'admin n'est jamais
 * écrasée, et la commande peut être relancée sans risque (déploiement compris).
 *
 * Usage : php bin/console app:calculator:load-reference-data
 *
 * Liens :
 * - Utilise : Fluid, Printer3d, EnergyProvider, Refrigerant, FGasRule, ClimaticProfile et leurs dépôts
 * - Voir aussi : Admin\FluidController, Admin\Printer3dController, Admin\EnergyProviderController,
 *   Admin\RefrigerantController, Admin\FGasRuleController, Admin\ClimaticProfileController
 */
#[AsCommand(name: 'app:calculator:load-reference-data', description: 'Ajoute les données de référence manquantes de la calculatrice')]
final class LoadReferenceDataCommand extends Command
{
    /**
     * Reçoit les dépendances.
     *
     * @param string                    $dataPath                  chemin de config/calculator/reference_data.yaml
     * @param FluidRepository           $fluidRepository           fluides existants
     * @param Printer3dRepository       $printerRepository         imprimantes existantes
     * @param EnergyProviderRepository  $providerRepository        offres existantes
     * @param EntityManagerInterface    $entityManager             gestionnaire Doctrine
     * @param string                    $refrigerantsPath          chemin de config/calculator/refrigerants.json
     * @param string                    $fgasRulesPath             chemin de config/calculator/fgas_rules.json
     * @param RefrigerantRepository     $refrigerantRepository     fluides frigorigènes existants
     * @param FGasRuleRepository        $fgasRuleRepository        règles F-Gas existantes
     * @param string                    $climaticProfilesPath      chemin de config/calculator/climatic_profiles.json
     * @param ClimaticProfileRepository $climaticProfileRepository profils de l'enceinte climatique existants
     */
    public function __construct(
        #[Autowire('%kernel.project_dir%/config/calculator/reference_data.yaml')]
        private readonly string $dataPath,
        private readonly FluidRepository $fluidRepository,
        private readonly Printer3dRepository $printerRepository,
        private readonly EnergyProviderRepository $providerRepository,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%kernel.project_dir%/config/calculator/refrigerants.json')]
        private readonly string $refrigerantsPath,
        #[Autowire('%kernel.project_dir%/config/calculator/fgas_rules.json')]
        private readonly string $fgasRulesPath,
        private readonly RefrigerantRepository $refrigerantRepository,
        private readonly FGasRuleRepository $fgasRuleRepository,
        #[Autowire('%kernel.project_dir%/config/calculator/climatic_profiles.json')]
        private readonly string $climaticProfilesPath,
        private readonly ClimaticProfileRepository $climaticProfileRepository,
    ) {
        parent::__construct();
    }

    /**
     * Ajoute les données manquantes et affiche le nombre d'éléments ajoutés.
     *
     * Liens :
     * - Appelée par : la console Symfony, scripts/deploy.sh
     *
     * @return int Command::SUCCESS
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var array{fluids: list<array{name: string, state: string, density: float, specific_heat: float}>, printers: list<array{brand: string, model: string, power: int}>, energy_providers: list<array{name: string, plan: string, price_kwh: float}>} $data */
        $data = Yaml::parseFile($this->dataPath);

        $added = ['fluids' => 0, 'printers' => 0, 'providers' => 0];
        $position = $this->fluidRepository->findMaxPosition();
        foreach ($data['fluids'] as $row) {
            if (null === $this->fluidRepository->findOneBy(['name' => $row['name']])) {
                $this->entityManager->persist((new Fluid())
                    ->setName($row['name'])
                    ->setState(FluidState::from($row['state']))
                    ->setDensity((float) $row['density'])
                    ->setSpecificHeatCapacity((float) $row['specific_heat'])
                    ->setPosition(++$position));
                ++$added['fluids'];
            }
        }

        $position = $this->printerRepository->findMaxPosition();
        foreach ($data['printers'] as $row) {
            if (null === $this->printerRepository->findOneBy(['brand' => $row['brand'], 'model' => $row['model']])) {
                $this->entityManager->persist((new Printer3d())
                    ->setBrand($row['brand'])
                    ->setModel($row['model'])
                    ->setPowerConsumption((int) $row['power'])
                    ->setPosition(++$position));
                ++$added['printers'];
            }
        }

        $position = $this->providerRepository->findMaxPosition();
        foreach ($data['energy_providers'] as $row) {
            if (null === $this->providerRepository->findOneBy(['name' => $row['name'], 'planName' => $row['plan']])) {
                $this->entityManager->persist((new EnergyProvider())
                    ->setName($row['name'])
                    ->setPlanName($row['plan'])
                    ->setPriceKwh((float) $row['price_kwh'])
                    ->setPosition(++$position));
                ++$added['providers'];
            }
        }

        $added['refrigerants'] = $this->loadRefrigerants();
        $added['rules'] = $this->loadFGasRules();
        $added['profiles'] = $this->loadClimaticProfiles();

        $this->entityManager->flush();
        $io->success(\sprintf('Ajoutés : %d fluide(s), %d imprimante(s), %d offre(s) d\'électricité, %d fluide(s) frigorigène(s), %d règle(s) F-Gas, %d profil(s) climatique(s). Les données existantes n\'ont pas été modifiées.', $added['fluids'], $added['printers'], $added['providers'], $added['refrigerants'], $added['rules'], $added['profiles']));

        return Command::SUCCESS;
    }

    /**
     * Ajoute les fluides frigorigènes manquants (repérés par leur code), à vérifier dans l'admin.
     *
     * Liens :
     * - Appelée par : execute()
     *
     * @return int nombre de fluides ajoutés
     */
    private function loadRefrigerants(): int
    {
        /** @var array{refrigerants: list<array{code: string, name: string, family: string, gwp: int, gwpReport: string, status: string, class: string, lfl: float|int|null, glide: float|int|null, oil: string, replacement: string, application: string, default?: bool}>} $data */
        $data = json_decode((string) file_get_contents($this->refrigerantsPath), true, flags: \JSON_THROW_ON_ERROR);

        $added = 0;
        $position = $this->refrigerantRepository->findMaxPosition();
        foreach ($data['refrigerants'] as $row) {
            if (null === $this->refrigerantRepository->findOneBy(['code' => $row['code']])) {
                $this->entityManager->persist((new Refrigerant())
                    ->setCode($row['code'])
                    ->setName($row['name'])
                    ->setFamily($row['family'])
                    ->setGwp($row['gwp'])
                    ->setGwpReport($row['gwpReport'])
                    ->setStatus($row['status'])
                    ->setSafetyClass($row['class'])
                    ->setLfl(null === $row['lfl'] ? null : (float) $row['lfl'])
                    ->setGlide(null === $row['glide'] ? null : (float) $row['glide'])
                    ->setOil($row['oil'])
                    ->setReplacement($row['replacement'])
                    ->setApplication($row['application'])
                    ->setDefaultChoice($row['default'] ?? false)
                    ->setPosition(++$position));
                ++$added;
            }
        }

        return $added;
    }

    /**
     * Ajoute les règles F-Gas manquantes (repérées par leur code), non vérifiées : elles restent
     * masquées sur le site tant qu'elles ne sont pas cochées « Vérifiée » dans l'admin.
     *
     * Liens :
     * - Appelée par : execute()
     *
     * @return int nombre de règles ajoutées
     */
    private function loadFGasRules(): int
    {
        /** @var array{rules: list<array{code: string, kind: string, equipment: string, types: list<string>, minCapacityKw: float|int|null, minChargeTonnes: float|int|null, gwp: int, since: string, exception: ?string, article: string, annexPoint: ?string, source: string}>} $data */
        $data = json_decode((string) file_get_contents($this->fgasRulesPath), true, flags: \JSON_THROW_ON_ERROR);

        $added = 0;
        $position = $this->fgasRuleRepository->findMaxPosition();
        foreach ($data['rules'] as $row) {
            if (null === $this->fgasRuleRepository->findOneBy(['code' => $row['code']])) {
                $this->entityManager->persist((new FGasRule())
                    ->setCode($row['code'])
                    ->setKind($row['kind'])
                    ->setEquipment($row['equipment'])
                    ->setInstallationTypes($row['types'])
                    ->setMinCapacityKw(null === $row['minCapacityKw'] ? null : (float) $row['minCapacityKw'])
                    ->setMinChargeTonnes(null === $row['minChargeTonnes'] ? null : (float) $row['minChargeTonnes'])
                    ->setGwpThreshold($row['gwp'])
                    ->setSince(new \DateTimeImmutable($row['since']))
                    ->setException($row['exception'])
                    ->setArticle($row['article'])
                    ->setAnnexPoint($row['annexPoint'])
                    ->setSource($row['source'])
                    ->setPosition(++$position));
                ++$added;
            }
        }

        return $added;
    }

    /**
     * Ajoute les profils de consigne de l'enceinte climatique manquants (repérés par leur code), à vérifier dans l'admin.
     *
     * Liens :
     * - Appelée par : execute()
     *
     * @return int nombre de profils ajoutés
     */
    private function loadClimaticProfiles(): int
    {
        /** @var array{profiles: list<array{code: string, sector: string, name: string, tSet: float|int, rhSet: float|int|null, tolT: float|int, tolRh: float|int|null, source: string, default?: bool}>} $data */
        $data = json_decode((string) file_get_contents($this->climaticProfilesPath), true, flags: \JSON_THROW_ON_ERROR);

        $added = 0;
        $position = $this->climaticProfileRepository->findMaxPosition();
        foreach ($data['profiles'] as $row) {
            if (null === $this->climaticProfileRepository->findOneBy(['code' => $row['code']])) {
                $this->entityManager->persist((new ClimaticProfile())
                    ->setCode($row['code'])
                    ->setSector($row['sector'])
                    ->setName($row['name'])
                    ->setTemperature((float) $row['tSet'])
                    ->setHumidity(null === $row['rhSet'] ? null : (float) $row['rhSet'])
                    ->setTemperatureTolerance((float) $row['tolT'])
                    ->setHumidityTolerance(null === $row['tolRh'] ? null : (float) $row['tolRh'])
                    ->setSource($row['source'])
                    ->setDefaultChoice($row['default'] ?? false)
                    ->setPosition(++$position));
                ++$added;
            }
        }

        return $added;
    }
}
