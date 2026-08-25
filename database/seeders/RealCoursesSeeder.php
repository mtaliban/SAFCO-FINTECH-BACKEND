<?php

namespace Database\Seeders;

use App\Models\Assignment;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use App\Models\LessonMaterial;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds 5 full professional courses + a dedicated trainer account.
 * Trainer: Hamisiselemani200@gmail.com / 12345678
 *
 * Courses created (status = published, free):
 *   1. Microsoft Excel Mastery          (excel, beginner→intermediate)
 *   2. Power Query & Data Transformation (power_query, intermediate)
 *   3. Power BI for Business Analytics  (power_bi, intermediate)
 *   4. Financial Accounting & IFRS      (accounting, beginner)
 *   5. Python for Data Analytics        (data_analytics, intermediate)
 */
class RealCoursesSeeder extends Seeder
{
    public function run(): void
    {
        // ── 1. Create trainer ─────────────────────────────────────────
        $trainer = User::updateOrCreate(
            ['email' => 'Hamisiselemani200@gmail.com'],
            [
                'password'           => Hash::make('12345678'),
                'status'             => 'active',
                'email_verified_at'  => now(),
            ]
        );
        $trainer->syncRoles(['trainer']);

        UserProfile::updateOrCreate(
            ['user_id' => $trainer->id],
            [
                'full_name'  => 'Hamisi Selemani',
                'first_name' => 'Hamisi',
                'last_name'  => 'Selemani',
                'position'   => 'Senior Finance & Technology Trainer',
                'country'    => 'Tanzania',
            ]
        );

        // ── 2. Build courses ──────────────────────────────────────────
        $this->excelCourse($trainer->id);
        $this->powerQueryCourse($trainer->id);
        $this->powerBiCourse($trainer->id);
        $this->accountingCourse($trainer->id);
        $this->pythonCourse($trainer->id);

        $this->command->info('✓ RealCoursesSeeder: 5 courses created for Hamisiselemani200@gmail.com');
    }

    // =========================================================================
    // COURSE 1 — Microsoft Excel Mastery
    // =========================================================================
    private function excelCourse(int $trainerId): void
    {
        $course = Course::updateOrCreate(
            ['title' => 'Microsoft Excel Mastery: Beginner to Advanced'],
            [
                'description'    => 'Jifunza Excel kutoka mwanzo kabisa hadi kiwango cha juu cha kitaalamu. Utajifunza formulas, functions, PivotTables, charts, na data analysis — zana ambazo zinahitajika katika kazi yoyote ya biashara au fedha.',
                'category'       => 'excel',
                'level'          => 'beginner',
                'duration_hours' => 18,
                'price_tzs'      => 0,
                'instructor_id'  => $trainerId,
                'created_by'     => $trainerId,
                'status'         => 'published',
                'published_at'   => now(),
                'approved_at'    => now(),
            ]
        );

        // MODULE 1
        $m1 = $this->mod($course, 1, 'Misingi ya Excel (Excel Fundamentals)', 'Jifunza interface, navigation, na data entry. Hii ni msingi wa kila kitu kingine.');
        $this->lesson($m1, 1, 'Excel Interface na Navigation', 'Jifunza ribbon, cells, rows, columns, na jinsi ya kusogea kwenye spreadsheet kwa ufanisi.', 720,
            [['video_youtube', 'Interface ya Excel - Mwongozo Kamili', 'https://www.youtube.com/watch?v=rwbho0CgEAI']]);
        $this->lesson($m1, 2, 'Kuingiza na Kuformat Data', 'Aina za data (text, numbers, dates), formatting (bold, colors, borders), na cell alignment.', 900,
            [['video_youtube', 'Data Entry na Formatting', 'https://www.youtube.com/watch?v=p_A2J6YoMOk']]);
        $this->lesson($m1, 3, 'Formulas za Msingi: SUM, AVERAGE, COUNT', 'Jifunza jinsi ya kuandika formulas — SUM, AVERAGE, COUNT, MAX, MIN. Msingi wa Excel calculations.', 1080,
            [['video_youtube', 'Basic Formulas - SUM, AVERAGE, COUNT', 'https://www.youtube.com/watch?v=QFOA9uDvn4s']]);
        $this->assignment($m1->lessons()->first(), 'Kazi ya Mwanzo: Bajeti ya Mwezi', 'Tengeneza spreadsheet ya bajeti ya familia yako kwa mwezi mmoja. Weka categories: Chakula, Usafiri, Kodi, Burudani. Tumia SUM kuona jumla ya matumizi. Wasilisha kama Excel file (.xlsx)', 50, 7);

        // MODULE 2
        $m2 = $this->mod($course, 2, 'Functions za Nguvu (Power Functions)', 'VLOOKUP, IF, COUNTIF — functions ambazo zinatumika kila siku katika biashara.');
        $this->lesson($m2, 1, 'Logical Functions: IF, AND, OR', 'Jifunza IF statement — jinsi ya kuamua kati ya matokeo mawili kulingana na hali. AND na OR kwa masharti mengi.', 1200,
            [['video_youtube', 'Excel IF Function - Mfano wa Kweli', 'https://www.youtube.com/watch?v=3YMHhZHJzb4']]);
        $this->lesson($m2, 2, 'Lookup Functions: VLOOKUP na INDEX/MATCH', 'VLOOKUP ni moja ya functions muhimu zaidi katika Excel. Jifunza kutafuta data kutoka tables kubwa. INDEX/MATCH ni mbadala bora zaidi.', 1440,
            [['video_youtube', 'VLOOKUP Tutorial - Mwongozo Kamili', 'https://www.youtube.com/watch?v=d3BYVQ6xIE4']]);
        $this->lesson($m2, 3, 'Text Functions: TRIM, CONCATENATE, LEFT/RIGHT', 'Kushughulikia text data — kuunganisha, kukata, kusafisha spaces, kubadilisha UPPER/lower case.', 900,
            [['video_youtube', 'Excel Text Functions', 'https://www.youtube.com/watch?v=rgnvL9P4Q-I']]);
        $this->lesson($m2, 4, 'SUMIF, COUNTIF, AVERAGEIF', 'Hesabu za masharti — jumla ya nambari zinazokidhi criteria fulani. Muhimu sana kwa ripoti za biashara.', 1080,
            [['video_youtube', 'SUMIF na COUNTIF Functions', 'https://www.youtube.com/watch?v=qZS-c_tq3IE']]);
        $this->assignment($m2->lessons()->orderBy('position')->skip(1)->first(), 'Inventory Analysis kwa VLOOKUP', 'Pewa dataset ya bidhaa 50. Tumia VLOOKUP kupata bei za bidhaa kutoka orodha nyingine. Tumia SUMIF kuhesabu jumla ya mauzo kwa kila aina ya bidhaa.', 80, 10);

        // MODULE 3
        $m3 = $this->mod($course, 3, 'Data Analysis na Visualization', 'PivotTables, charts, conditional formatting — jinsi ya kuwasilisha data kwa njia inayoeleweka.');
        $this->lesson($m3, 1, 'PivotTables — Uchambuzi wa Data Haraka', 'PivotTable ni chombo chenye nguvu zaidi katika Excel. Jifunza kuunda, kuformat, na kutumia PivotTables kuchambua data kubwa kwa sekunde chache.', 1800,
            [['video_youtube', 'PivotTables - Mwongozo wa Kweli', 'https://www.youtube.com/watch?v=UsdedFoTA68']]);
        $this->lesson($m3, 2, 'Charts na Graphs za Kitaalamu', 'Column charts, line charts, pie charts, bar charts. Jinsi ya kuchagua chart inayofaa data yako na kuiformat kwa hali ya juu.', 1200,
            [['video_youtube', 'Excel Charts na Graphs', 'https://www.youtube.com/watch?v=DAU0qqh_I-A']]);
        $this->lesson($m3, 3, 'Conditional Formatting', 'Weka rangi za moja kwa moja kulingana na thamani za data. Heat maps, data bars, icon sets — kuona patterns haraka.', 900,
            [['video_youtube', 'Conditional Formatting kwa Undani', 'https://www.youtube.com/watch?v=TtcSK_e67SM']]);
        $this->lesson($m3, 4, 'Dashboard ya Excel — Mradi wa Mwisho', 'Unganisha PivotTables, charts, na slicers kuunda dashboard ya kitaalamu ambayo inasasishwa moja kwa moja unapoongeza data.', 2400,
            [['video_youtube', 'Excel Dashboard Tutorial', 'https://www.youtube.com/watch?v=K74_FNs6dsE']]);
        $this->assignment($m3->lessons()->orderBy('position')->first(), 'Sales Dashboard Kamili', 'Pewa dataset ya mauzo ya mwaka mzima (miezi 12). Unda dashboard inayoonyesha: (1) Jumla ya mauzo kwa mwezi, (2) Bidhaa 5 zinazouza zaidi, (3) Chart ya mwelekeo wa mauzo, (4) PivotTable ya mauzo kwa region.', 100, 14);
    }

    // =========================================================================
    // COURSE 2 — Power Query & Data Transformation
    // =========================================================================
    private function powerQueryCourse(int $trainerId): void
    {
        $course = Course::updateOrCreate(
            ['title' => 'Power Query: Ubadilishaji wa Data kwa Ufanisi'],
            [
                'description'    => 'Power Query ni chombo cha kisasa cha kuunganisha, kusafisha, na kubadilisha data kutoka vyanzo vingi. Jifunza kuautomatisha kazi za data zitakazochukua masaa kwa dakika chache tu.',
                'category'       => 'power_query',
                'level'          => 'intermediate',
                'duration_hours' => 14,
                'price_tzs'      => 0,
                'instructor_id'  => $trainerId,
                'created_by'     => $trainerId,
                'status'         => 'published',
                'published_at'   => now(),
                'approved_at'    => now(),
            ]
        );

        $m1 = $this->mod($course, 1, 'Kuanza na Power Query', 'Introduction, interface, na kuunganisha na vyanzo vya data — Excel, CSV, na databases.');
        $this->lesson($m1, 1, 'Power Query ni Nini na Kwa Nini Itumie?', 'Tofauti kati ya kufanya kazi kwa mkono na kwa Power Query. Mifano ya kweli ya biashara ambapo Power Query inaokoa wakati.', 900,
            [['video_youtube', 'Intro ya Power Query - Badilisha Jinsi Unavyofanya Kazi', 'https://www.youtube.com/watch?v=0aeZX1l4JT4']]);
        $this->lesson($m1, 2, 'Kuunganisha CSV, Excel, na Folders', 'Jinsi ya kupakua data kutoka CSV file, Excel file, na folder nzima ya files. Auto-refresh ya data.', 1200,
            [['video_youtube', 'Power Query - Kuunganisha Vyanzo vya Data', 'https://www.youtube.com/watch?v=KxQn6MFbUyM']]);
        $this->lesson($m1, 3, 'Basic Transformations: Filter, Sort, Rename', 'Kuchuja safu, kupanga, kubadilisha majina ya columns. Kila hatua inarekodiwa na inaweza kubadilishwa baadaye.', 1080,
            [['video_youtube', 'Power Query Basic Transformations', 'https://www.youtube.com/watch?v=7q9aFKGXFXE']]);

        $m2 = $this->mod($course, 2, 'Mabadiliko ya Kina (Advanced Transformations)', 'Merge, append, unpivot, custom columns — zana za kubadilisha data ngumu.');
        $this->lesson($m2, 1, 'Merge Queries — Kuunganisha Tables', 'Kama VLOOKUP lakini kwa nguvu zaidi. Aina zote za joins: left, right, inner, outer. Mfano wa kweli wa biashara.', 1440,
            [['video_youtube', 'Power Query Merge Queries', 'https://www.youtube.com/watch?v=Dp8yd7RoEbk']]);
        $this->lesson($m2, 2, 'Append Queries — Kuunganisha Safu mlalo', 'Kuchanganya data kutoka tables nyingi kuwa moja. Mfano: ripoti za mauzo za miezi 12 tofauti kuwa moja.', 1200,
            [['video_youtube', 'Append Queries kwa Undani', 'https://www.youtube.com/watch?v=sxSZL3oyMJ4']]);
        $this->lesson($m2, 3, 'Unpivot — Kubadilisha Columns kuwa Rows', 'Data pivot vs unpivot — muhimu sana kwa data ambayo imepangwa vibaya. Mfano: kubadilisha ripoti ya kawaida iwe tayari kwa PivotTable.', 1080,
            [['video_youtube', 'Unpivot Data kwa Power Query', 'https://www.youtube.com/watch?v=3ZGm5HC70vI']]);
        $this->lesson($m2, 4, 'Custom Columns na M Language Basics', 'Andika formulas zako za Power Query (M Language). Kutengeneza columns mpya kwa kuhesabu au kubadilisha data.', 1440,
            [['video_youtube', 'M Language Basics kwa Beginners', 'https://www.youtube.com/watch?v=e4IQ80nXUqc']]);
        $this->assignment($m2->lessons()->orderBy('position')->first(), 'Data Cleaning Mradi', 'Pewa dataset chafu ya wateja 200 (na herufi kubwa ndogo zilizochanganywa, spaces za ziada, tarehe katika format tofauti). Tumia Power Query kusafisha: standardize majina, format tarehe, ondoa duplicates. Wasilisha: (1) Excel file iliyo safi, (2) Screenshot ya Power Query steps.', 90, 12);

        $m3 = $this->mod($course, 3, 'Power Query kwa Vitendo (Real-world)', 'Mradi wa mwisho na mbinu za kitaalamu za Production.');
        $this->lesson($m3, 1, 'Kuunganisha na Database (SQL Server, SharePoint)', 'Jinsi ya kuunganisha Power Query na SQL Server, SharePoint, na vyanzo vya web. Refresh ya moja kwa moja.', 1200,
            [['video_youtube', 'Power Query na Database Connection', 'https://www.youtube.com/watch?v=s8oLi_JLMLA']]);
        $this->lesson($m3, 2, 'Error Handling na Troubleshooting', 'Jinsi ya kushughulikia makosa katika Power Query bila kupoteza data. try...otherwise na error columns.', 900,
            [['video_youtube', 'Power Query Error Handling', 'https://www.youtube.com/watch?v=OLW6Md-3yOI']]);
        $this->lesson($m3, 3, 'Automated Monthly Reporting', 'Jenga mfumo wa ripoti za kila mwezi ambao unasasishwa kwa click moja. Unganisha data kutoka folders nyingi kiotomatiki.', 1800,
            [['video_youtube', 'Automated Reports na Power Query', 'https://www.youtube.com/watch?v=xhqFYaJfzFA']]);
        $this->assignment($m3->lessons()->orderBy('position')->first(), 'Automated Report ya Kila Mwezi', 'Unda mfumo kamili wa Power Query unaounganisha mauzo ya miezi 12 (files 12 tofauti) kuwa ripoti moja. Report inapaswa kuonyesha mwelekeo wa mauzo. Dakika 2 kufanya refresh badala ya masaa 4 ya kufanya kwa mkono.', 100, 14);
    }

    // =========================================================================
    // COURSE 3 — Power BI for Business Analytics
    // =========================================================================
    private function powerBiCourse(int $trainerId): void
    {
        $course = Course::updateOrCreate(
            ['title' => 'Power BI: Uchambuzi wa Biashara na Dashboards'],
            [
                'description'    => 'Jifunza Power BI kutoka mwanzo — kupakia data, kuunda measures za DAX, kutengeneza dashboards za kisasa, na kushiriki ripoti na timu yako. Chombo kinachotumika na makampuni makubwa duniani.',
                'category'       => 'power_bi',
                'level'          => 'intermediate',
                'duration_hours' => 20,
                'price_tzs'      => 0,
                'instructor_id'  => $trainerId,
                'created_by'     => $trainerId,
                'status'         => 'published',
                'published_at'   => now(),
                'approved_at'    => now(),
            ]
        );

        $m1 = $this->mod($course, 1, 'Misingi ya Power BI', 'Power BI Desktop interface, kuunganisha data, na kuunda visuals vya kwanza.');
        $this->lesson($m1, 1, 'Power BI Desktop — Interface na Mwongozo wa Kwanza', 'Jifunza sehemu zote za Power BI Desktop: Report view, Data view, Model view. Unda visual ya kwanza ndani ya dakika 10.', 900,
            [['video_youtube', 'Power BI Desktop Intro — Kuanza Tangu Sifuri', 'https://www.youtube.com/watch?v=TmhQCQr_0aA']]);
        $this->lesson($m1, 2, 'Kupakia Data na Power Query ndani ya Power BI', 'Power Query ipo ndani ya Power BI pia! Jifunza kuunganisha Excel, CSV, SQL Server. Kusafisha data kabla ya kuunda ripoti.', 1200,
            [['video_youtube', 'Power BI Data Loading na Transformation', 'https://www.youtube.com/watch?v=AGrl-H87pRU']]);
        $this->lesson($m1, 3, 'Data Model — Relationships kati ya Tables', 'Star schema vs snowflake schema. Jinsi ya kuunda relationships sahihi kati ya fact tables na dimension tables. Hii ni msingi wa DAX.', 1440,
            [['video_youtube', 'Power BI Data Modeling — Relationships', 'https://www.youtube.com/watch?v=MrLnibFTtbA']]);
        $this->lesson($m1, 4, 'Basic Visuals — Charts, Tables, Cards', 'Bar chart, line chart, pie chart, table, matrix, card. Jinsi ya kuformat kila visual na kufanya iwe ya kisomi.', 1200,
            [['video_youtube', 'Power BI Visuals — Mwongozo Kamili', 'https://www.youtube.com/watch?v=h5HCpxQsOjU']]);

        $m2 = $this->mod($course, 2, 'DAX — Lugha ya Power BI', 'DAX (Data Analysis Expressions) — lugha inayotumika kuunda measures na calculated columns.');
        $this->lesson($m2, 1, 'DAX ni Nini? Measures vs Calculated Columns', 'Tofauti muhimu kati ya calculated columns (zinazohifadhiwa) na measures (zinazohesabiwa kwa wakati). Lini kutumia kila moja.', 1200,
            [['video_youtube', 'DAX Basics — Intro kwa Beginners', 'https://www.youtube.com/watch?v=5I-e6xVfDV0']]);
        $this->lesson($m2, 2, 'DAX Functions za Msingi: SUM, COUNT, AVERAGE, CALCULATE', 'CALCULATE ni function muhimu zaidi katika DAX. Jifunza jinsi inavyobadilisha filter context na kufanya calculations ngumu kuwa rahisi.', 1440,
            [['video_youtube', 'CALCULATE Function — Power ya DAX', 'https://www.youtube.com/watch?v=DSv5PCYGZ-E']]);
        $this->lesson($m2, 3, 'Time Intelligence: Year-to-Date, Month-over-Month', 'DAX time functions — TOTALYTD, DATEADD, SAMEPERIODLASTYEAR. Kulinganisha mauzo ya mwaka huu na mwaka uliopita kwa ease.', 1440,
            [['video_youtube', 'Time Intelligence DAX Functions', 'https://www.youtube.com/watch?v=E8D4SVUTJGU']]);
        $this->lesson($m2, 4, 'Filter Context na Row Context', 'Dhana muhimu sana katika DAX ambayo wengi hawaielewe vizuri. Kuelewa hii kutakufanya mtaalamu wa DAX.', 1200,
            [['video_youtube', 'Filter Context na Row Context — Kwa Undani', 'https://www.youtube.com/watch?v=q1FBBZ0acag']]);
        $this->assignment($m2->lessons()->orderBy('position')->first(), 'DAX Measures za Biashara', 'Pewa data model ya duka la rejareja. Unda measures hizi: (1) Total Sales, (2) Sales Growth %, (3) YTD Sales, (4) Average Transaction Value, (5) Customer Count. Kila measure iwe na maelezo.', 100, 10);

        $m3 = $this->mod($course, 3, 'Dashboard ya Kitaalamu na Kushiriki', 'Unda dashboards nzuri na uzishirikishe na timu kupitia Power BI Service.');
        $this->lesson($m3, 1, 'Report Design — Layout, Colors, na Branding', 'Kanuni za design ya professional report: white space, consistent colors, font sizes, alignment. Templates na themes.', 1080,
            [['video_youtube', 'Power BI Report Design Best Practices', 'https://www.youtube.com/watch?v=c7LrqSxjJQQ']]);
        $this->lesson($m3, 2, 'Slicers, Filters, na Drill-through', 'Kufanya ripoti yako interactive — slicers za tarehe, dropdowns, cross-filtering kati ya visuals. Drill-through kwa undani wa data.', 1200,
            [['video_youtube', 'Slicers na Filters katika Power BI', 'https://www.youtube.com/watch?v=7-2VePPcFvY']]);
        $this->lesson($m3, 3, 'Kuchapisha kwenye Power BI Service na Kushiriki', 'Publish report kwenye Power BI Service. Kuunda dashboards, kusetupia data refresh, na kushiriki na timu. Row-level security.', 1080,
            [['video_youtube', 'Power BI Service — Kushiriki Ripoti', 'https://www.youtube.com/watch?v=gqO0zx3qv3g']]);
        $this->lesson($m3, 4, 'Mradi wa Mwisho: Executive Dashboard', 'Unganisha maarifa yote — unda executive dashboard kamili inayoonyesha KPIs za biashara: mauzo, faida, wateja, na mwelekeo.', 3600,
            [['video_youtube', 'Power BI Executive Dashboard — Mradi Kamili', 'https://www.youtube.com/watch?v=8QXXrz14OUc']]);
        $this->assignment($m3->lessons()->orderBy('position')->skip(3)->first(), 'Executive Sales Dashboard', 'Unda Power BI dashboard kamili kwa data ya mauzo ya miaka 3. Lazima iwe na: (1) KPI cards (Revenue, Profit, Customers), (2) Trend chart ya kila mwezi, (3) Top 10 products, (4) Regional map, (5) Year-over-year comparison. Wasilisha .pbix file na screenshot.', 120, 21);
    }

    // =========================================================================
    // COURSE 4 — Financial Accounting & IFRS
    // =========================================================================
    private function accountingCourse(int $trainerId): void
    {
        $course = Course::updateOrCreate(
            ['title' => 'Uhasibu wa Fedha na Viwango vya IFRS'],
            [
                'description'    => 'Jifunza misingi ya uhasibu — double entry, financial statements, na viwango vya kimataifa vya IFRS. Kozi hii inafaa kwa wanaohesabu, managers wa fedha, na wajasiriamali wanaotaka kuelewa fedha za kampuni yao.',
                'category'       => 'accounting',
                'level'          => 'beginner',
                'duration_hours' => 22,
                'price_tzs'      => 0,
                'instructor_id'  => $trainerId,
                'created_by'     => $trainerId,
                'status'         => 'published',
                'published_at'   => now(),
                'approved_at'    => now(),
            ]
        );

        $m1 = $this->mod($course, 1, 'Misingi ya Uhasibu (Accounting Fundamentals)', 'Kanuni za msingi za uhasibu, mfumo wa double-entry, na aina za akaunti.');
        $this->lesson($m1, 1, 'Uhasibu ni Nini na Kwa Nini ni Muhimu?', 'Maana ya uhasibu, tofauti kati ya bookkeeping na accounting, na umuhimu wake katika biashara yoyote kubwa au ndogo.', 900,
            [['video_youtube', 'Intro ya Uhasibu kwa Beginners', 'https://www.youtube.com/watch?v=yYX4bvQSqbo']]);
        $this->lesson($m1, 2, 'Double Entry System — Mfumo wa Ingizo Mbili', 'Kila muamala wa biashara una pande mbili — debit na credit. Jifunza kanuni hii muhimu zaidi ya uhasibu na jinsi inavyofanya kazi.', 1440,
            [['video_youtube', 'Double Entry Accounting Explained', 'https://www.youtube.com/watch?v=2aaHEH7gjXw']]);
        $this->lesson($m1, 3, 'Aina za Akaunti: Assets, Liabilities, Equity', 'Jifunza accounting equation: Assets = Liabilities + Equity. Aina za akaunti na jinsi zinavyoathiriana.', 1200,
            [['video_youtube', 'Accounting Equation na Account Types', 'https://www.youtube.com/watch?v=hj7nJCLCHoI']]);
        $this->lesson($m1, 4, 'Journal Entries — Kuandika Muamala', 'Jinsi ya kuandika journal entries sahihi kwa muamala wa kawaida: mauzo, ununuzi, malipo ya mshahara, depreciation.', 1440,
            [['video_youtube', 'Journal Entries — Mifano ya Kweli', 'https://www.youtube.com/watch?v=YM9lj5pMuNw']]);
        $this->assignment($m1->lessons()->orderBy('position')->skip(3)->first(), 'Journal Entries za Kampuni Mpya', 'Kampuni mpya ya biashara ilifanya miamala hii: (1) Wekeza mtaji TZS 5M, (2) Nunua bidhaa kwa TZS 2M cash, (3) Uza bidhaa kwa TZS 1.5M cash, (4) Lipa kodi TZS 300K, (5) Nunua compyuta TZS 800K. Andika journal entries zote na onyesha T-accounts.', 80, 10);

        $m2 = $this->mod($course, 2, 'Ripoti za Fedha (Financial Statements)', 'Taarifa ya Mapato, Mizania, na Taarifa ya Mtiririko wa Pesa — jinsi ya kuziandaa na kuzisoma.');
        $this->lesson($m2, 1, 'Income Statement — Taarifa ya Mapato', 'Jinsi ya kuandaa Income Statement. Revenue, Cost of Goods Sold, Gross Profit, Operating Expenses, Net Profit. Mfano kamili.', 1440,
            [['video_youtube', 'Income Statement — Jinsi ya Kuandaa', 'https://www.youtube.com/watch?v=WEDIj9JBTC8']]);
        $this->lesson($m2, 2, 'Balance Sheet — Mizania ya Kampuni', 'Mizania inaonyesha hali ya fedha ya kampuni siku fulani. Assets, Liabilities, Shareholders Equity. Jinsi ya kusoma na kuchambua.', 1440,
            [['video_youtube', 'Balance Sheet Explained', 'https://www.youtube.com/watch?v=e19nC9y7HBo']]);
        $this->lesson($m2, 3, 'Cash Flow Statement — Taarifa ya Mtiririko wa Pesa', 'Tofauti kati ya profit na cash. Direct vs indirect method. Operating, investing, na financing activities.', 1440,
            [['video_youtube', 'Cash Flow Statement', 'https://www.youtube.com/watch?v=sI6xMNyFMPo']]);
        $this->lesson($m2, 4, 'Financial Ratios na Uchambuzi', 'Jinsi ya kutumia ratios kuchambua afya ya kampuni: Liquidity ratios, Profitability ratios, Leverage ratios.', 1200,
            [['video_youtube', 'Financial Ratio Analysis', 'https://www.youtube.com/watch?v=OMA6MnPxHLg']]);
        $this->assignment($m2->lessons()->orderBy('position')->first(), 'Andaa Financial Statements', 'Kutokana na trial balance iliyopewa, andaa: (1) Income Statement ya mwaka, (2) Balance Sheet mwisho wa mwaka, (3) Hesabu gross profit margin na net profit margin.', 100, 14);

        $m3 = $this->mod($course, 3, 'Viwango vya IFRS (International Financial Reporting Standards)', 'Viwango vya uhasibu vinavyotumika duniani — IFRS 15, 16, IAS 36, na vingine.');
        $this->lesson($m3, 1, 'IFRS ni Nini na Kwa Nini Zinatumika?', 'Historia ya IFRS, tofauti kati ya IFRS na local GAAP, nchi zinazotumia IFRS, na umuhimu wa ulinganifu wa kimataifa.', 1080,
            [['video_youtube', 'IFRS Introduction — Kwa Beginners', 'https://www.youtube.com/watch?v=Xo-UR8PEZS8']]);
        $this->lesson($m3, 2, 'IFRS 15 — Utambuzi wa Mapato (Revenue Recognition)', 'Hatua 5 za kutambua mapato kulingana na IFRS 15. Mifano ya mikataba ngumu — subscription services, long-term projects.', 1440,
            [['video_youtube', 'IFRS 15 Revenue Recognition Explained', 'https://www.youtube.com/watch?v=yvHFmWHBJCo']]);
        $this->lesson($m3, 3, 'IFRS 16 — Mikataba ya Kukodisha (Leases)', 'Jinsi IFRS 16 ilibadilisha jinsi kampuni zinavyoonyesha mikataba ya kukodisha katika balance sheet. Right-of-use assets na lease liabilities.', 1200,
            [['video_youtube', 'IFRS 16 Leases Simplified', 'https://www.youtube.com/watch?v=zM38bFHqfVo']]);
        $this->lesson($m3, 4, 'IAS 36 — Kupungua kwa Thamani ya Mali (Impairment)', 'Jinsi ya kujua kama mali ina impairment, jinsi ya kuhesabu, na jinsi ya kurekodi. Cash Generating Units (CGUs).', 1080,
            [['video_youtube', 'IAS 36 Impairment of Assets', 'https://www.youtube.com/watch?v=2R1gw9QZFYI']]);
        $this->assignment($m3->lessons()->orderBy('position')->skip(1)->first(), 'IFRS 15 — Revenue Recognition Case Study', 'Kampuni ya software inauza: (1) Leseni ya programu TZS 10M kwa miaka 3, (2) Mafunzo ya wiki 2 TZS 2M, (3) Usaidizi wa kila mwaka TZS 1.5M/mwaka. Kwa kutumia IFRS 15 five-step model: tangaza bei za kila service na onyesha jinsi ya kutambua mapato kwa miaka 3.', 100, 21);
    }

    // =========================================================================
    // COURSE 5 — Python for Data Analytics
    // =========================================================================
    private function pythonCourse(int $trainerId): void
    {
        $course = Course::updateOrCreate(
            ['title' => 'Python kwa Uchambuzi wa Data (Data Analytics)'],
            [
                'description'    => 'Jifunza Python kutoka mwanzo kabisa hadi kiwango cha kuchambua data kwa ufanisi. Pandas, NumPy, Matplotlib, na Seaborn — zana ambazo zinatumika na wachunguzi wa data duniani kote.',
                'category'       => 'data_analytics',
                'level'          => 'intermediate',
                'duration_hours' => 25,
                'price_tzs'      => 0,
                'instructor_id'  => $trainerId,
                'created_by'     => $trainerId,
                'status'         => 'published',
                'published_at'   => now(),
                'approved_at'    => now(),
            ]
        );

        $m1 = $this->mod($course, 1, 'Python — Misingi ya Lugha', 'Variables, data types, control flow, functions. Unahitaji kujua hivi kabla ya kuanza data analysis.');
        $this->lesson($m1, 1, 'Kuanzisha Python na Jupyter Notebook', 'Jinsi ya kuinstall Python, pip, na Jupyter Notebook. Kuelewa environment ya kufanya kazi. Hello World ya kwanza.', 900,
            [['video_youtube', 'Kuanza Python na Jupyter Notebook', 'https://www.youtube.com/watch?v=YYXdXT2l-Gg']]);
        $this->lesson($m1, 2, 'Variables, Data Types, na Operators', 'int, float, string, bool, list, dict, tuple. Jinsi ya kutangaza variables na kufanya operations za msingi.', 1080,
            [['video_youtube', 'Python Data Types na Variables', 'https://www.youtube.com/watch?v=khKv-8q7YmY']]);
        $this->lesson($m1, 3, 'Control Flow: if/elif/else, for loops, while', 'Kuamua na kurudia — jinsi Python inavyofanya maamuzi na jinsi ya kurudia kazi nyingi kiotomatiki.', 1200,
            [['video_youtube', 'Python Control Flow — Loops na Conditionals', 'https://www.youtube.com/watch?v=DZwmZ8Usvnk']]);
        $this->lesson($m1, 4, 'Functions na Modules', 'Jinsi ya kuandika functions reusable. Kuimport modules (math, os, datetime). Packages na pip install.', 1200,
            [['video_youtube', 'Python Functions na Modules', 'https://www.youtube.com/watch?v=9Os0o3wzS_I']]);
        $this->assignment($m1->lessons()->orderBy('position')->skip(3)->first(), 'Python Functions za Biashara', 'Andika script ya Python yenye functions hizi: (1) calculate_vat(price, rate=0.18) — irudishe jumla na VAT, (2) format_currency(amount, currency="TZS") — irudishe string nzuri, (3) categorize_spending(amount) — irudishe "Chini" (<100K), "Kati" (100K-500K), "Juu" (>500K). Jaribu kila function na mifano 3.', 70, 7);

        $m2 = $this->mod($course, 2, 'Pandas — Maktaba ya Data Analysis', 'Pandas ni maktaba muhimu zaidi ya Python kwa data analysis. DataFrames, Series, filtering, grouping.');
        $this->lesson($m2, 1, 'Pandas Basics — Series na DataFrame', 'Jinsi ya kuunda na kusoma DataFrames. Loading data kutoka CSV, Excel, na JSON. info(), describe(), head().', 1440,
            [['video_youtube', 'Pandas Tutorial — Kuanza na DataFrames', 'https://www.youtube.com/watch?v=vmEHCJofslg']]);
        $this->lesson($m2, 2, 'Data Selection, Filtering, na Sorting', 'loc vs iloc, boolean filtering, query(), sort_values(). Jinsi ya kupata data unayoitaka haraka.', 1440,
            [['video_youtube', 'Pandas Data Selection na Filtering', 'https://www.youtube.com/watch?v=txMdrV1Ut64']]);
        $this->lesson($m2, 3, 'GroupBy, Aggregation, na Pivot Tables', 'groupby() ni mfano wa PivotTable lakini kwa nguvu zaidi. agg(), pivot_table(). Mifano ya kweli ya biashara.', 1440,
            [['video_youtube', 'Pandas GroupBy na Aggregation', 'https://www.youtube.com/watch?v=Wb2Tp35dZ-I']]);
        $this->lesson($m2, 4, 'Cleaning Data kwa Pandas', 'Kushughulikia missing values (NaN), duplicates, outliers, na data ya aina tofauti. Pandas ni chombo bora cha data cleaning.', 1200,
            [['video_youtube', 'Data Cleaning na Pandas', 'https://www.youtube.com/watch?v=ZOX18HfLHGQ']]);
        $this->assignment($m2->lessons()->orderBy('position')->first(), 'Pandas Data Analysis', 'Pewa dataset ya mauzo ya miaka 2 (CSV file, rows 5000). Tumia Pandas: (1) Onyesha muhtasari wa data (info, describe), (2) Pata mauzo makubwa 10 ya juu, (3) Hesabu mauzo ya kila mwezi kwa groupby, (4) Toa safu zote zenye missing values, (5) Onyesha bidhaa 5 zinazouza vizuri zaidi. Wasilisha Jupyter Notebook.', 100, 14);

        $m3 = $this->mod($course, 3, 'Data Visualization na Mradi wa Mwisho', 'Matplotlib, Seaborn, na kuwasilisha matokeo ya data analysis kwa njia inayoeleweka.');
        $this->lesson($m3, 1, 'Matplotlib — Misingi ya Visualization', 'line plot, bar chart, scatter plot, histogram, pie chart. Kuformat: titles, labels, colors, legend.', 1440,
            [['video_youtube', 'Matplotlib Tutorial — Jinsi ya Kuplot Data', 'https://www.youtube.com/watch?v=UO98lJQ3QGI']]);
        $this->lesson($m3, 2, 'Seaborn — Statistical Visualizations', 'Seaborn inafanya statistical plots kuwa rahisi: heatmap, pairplot, boxplot, violin plot. Kuelewa distribution ya data.', 1200,
            [['video_youtube', 'Seaborn Tutorial kwa Data Scientists', 'https://www.youtube.com/watch?v=6GUZXDef2U0']]);
        $this->lesson($m3, 3, 'Exploratory Data Analysis (EDA)', 'Mchakato kamili wa kuelewa dataset mpya — univariate, bivariate, na multivariate analysis. Correlation matrix na insights.', 1800,
            [['video_youtube', 'Exploratory Data Analysis kwa Python', 'https://www.youtube.com/watch?v=-o3AxdVcUtQ']]);
        $this->lesson($m3, 4, 'Mradi wa Mwisho: Ripoti Kamili ya Data', 'Unganisha kila kitu — load data, clean, analyze na Pandas, visualize na Matplotlib/Seaborn, na unda ripoti ya professional.', 3600,
            [['video_youtube', 'Data Analysis Project — Kutoka Mwanzo hadi Mwisho', 'https://www.youtube.com/watch?v=r-uOLxNrNk8']]);
        $this->assignment($m3->lessons()->orderBy('position')->skip(3)->first(), 'End-to-End Data Analysis Project', 'Dataset: COVID-19 au FIFA players (chagua moja). Fanya analysis kamili: (1) Data cleaning, (2) EDA na statistics, (3) Charts 5+ tofauti kwa Matplotlib/Seaborn, (4) Insights 5 muhimu uliogundua, (5) Ripoti ya PDF na Jupyter Notebook. Hii ni kazi yako ya graduation!', 150, 21);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function mod(Course $course, int $pos, string $title, string $desc): CourseModule
    {
        return CourseModule::updateOrCreate(
            ['course_id' => $course->id, 'position' => $pos],
            ['title' => $title, 'description' => $desc]
        );
    }

    private function lesson(CourseModule $module, int $pos, string $title, string $desc, int $durationSec, array $materials): Lesson
    {
        $lesson = Lesson::updateOrCreate(
            ['course_module_id' => $module->id, 'position' => $pos],
            [
                'title'            => $title,
                'description'      => $desc,
                'duration_seconds' => $durationSec,
            ]
        );

        foreach ($materials as $i => [$type, $matTitle, $url]) {
            $embedId  = null;
            $embedUrl = null;
            if ($type === 'video_youtube') {
                preg_match('/(?:v=|youtu\.be\/)([\w-]{11})/', $url, $m);
                $embedId  = $m[1] ?? null;
                $embedUrl = $embedId ? "https://www.youtube.com/embed/{$embedId}?rel=0" : null;
            }
            LessonMaterial::updateOrCreate(
                ['lesson_id' => $lesson->id, 'position' => $i],
                [
                    'type'                => $type,
                    'title'               => $matTitle,
                    'description'         => null,
                    'url'                 => $url,
                    'mime_type'           => null,
                    'file_size'           => null,
                    'metadata'            => $embedId ? ['embed_id' => $embedId, 'embed_url' => $embedUrl] : [],
                    'processing_status'   => 'ready',
                    'processing_progress' => 100,
                ]
            );
        }

        return $lesson;
    }

    private function assignment(?Lesson $lesson, string $title, string $instructions, int $maxPts, int $dueDays): void
    {
        if (!$lesson) return;
        Assignment::updateOrCreate(
            ['lesson_id' => $lesson->id, 'title' => $title],
            [
                'instructions'       => $instructions,
                'max_points'         => $maxPts,
                'due_date'           => now()->addDays($dueDays),
                'allowed_file_types' => ['pdf', 'docx', 'xlsx', 'zip', 'ipynb'],
            ]
        );
    }
}
