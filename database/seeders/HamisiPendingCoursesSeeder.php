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
 * Seeds 5 full professional courses with status = pending_approval.
 * Trainer: hamisiselemani200@gmail.com / 12345678
 * Admin reviewer: hamisindwata300@gmail.com / 12345678
 *
 * Courses:
 *   1. Corporate Finance & Investment Analysis      (finance, advanced)
 *   2. IFRS Standards & Financial Reporting         (ifrs, intermediate)
 *   3. ERP Systems: Sage & QuickBooks Mastery       (erp_systems, beginner)
 *   4. Advanced Data Analytics with SQL & Excel     (data_analytics, advanced)
 *   5. Microsoft Office 365 Business Productivity   (microsoft_office, beginner)
 */
class HamisiPendingCoursesSeeder extends Seeder
{
    public function run(): void
    {
        // ── Ensure trainer exists ─────────────────────────────────────
        $trainer = User::updateOrCreate(
            ['email' => 'hamisiselemani200@gmail.com'],
            [
                'password'          => Hash::make('12345678'),
                'status'            => 'active',
                'email_verified_at' => now(),
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

        $this->financeCourse($trainer->id);
        $this->ifrsCourse($trainer->id);
        $this->erpCourse($trainer->id);
        $this->analyticsCourse($trainer->id);
        $this->officeCourse($trainer->id);

        $this->command->info('✓ HamisiPendingCoursesSeeder: 5 pending_approval courses created.');
    }

    // =========================================================================
    // COURSE 1 — Corporate Finance & Investment Analysis
    // =========================================================================
    private function financeCourse(int $tid): void
    {
        $c = Course::updateOrCreate(
            ['title' => 'Corporate Finance & Investment Analysis'],
            [
                'description'    => 'Jifunza misingi ya Corporate Finance — jinsi kampuni zinavyofanya maamuzi ya kifedha, jinsi ya tathmini uwekezaji, na jinsi ya kusimamia mtaji kwa ufanisi. Course hii inafunika NPV, IRR, WACC, Capital Structure, na Financial Modelling kwa mifano halisi ya kampuni za Afrika Mashariki.',
                'category'       => 'finance',
                'level'          => 'advanced',
                'duration_hours' => 24,
                'price_tzs'      => 0,
                'instructor_id'  => $tid,
                'created_by'     => $tid,
                'status'         => 'pending_approval',
            ]
        );

        // MODULE 1
        $m1 = $this->mod($c, 1, 'Misingi ya Corporate Finance', 'Jifunza jinsi kampuni zinavyofanya maamuzi ya kifedha na jinsi ya kusimamia mtaji.');

        $l = $this->lesson($m1, 1, 'Time Value of Money (TVM)', 'Dhana ya msingi: shilingi leo ina thamani zaidi kuliko shilingi kesho. Jifunza Present Value, Future Value, na Annuities.', 1200,
            [['video_youtube', 'Time Value of Money Explained', 'https://www.youtube.com/watch?v=3FVXdLAHFoc']],
            '<h2>Time Value of Money</h2><p>Dhana ya <strong>Time Value of Money (TVM)</strong> ni msingi wa fedha yote. Inamaanisha kwamba shilingi unayoipata leo ina thamani zaidi kuliko shilingi unayoipata baadaye — kwa sababu unaweza kuiwekeza leo na kupata riba.</p><h3>Present Value (PV)</h3><p>PV = FV ÷ (1 + r)<sup>n</sup></p><ul><li><strong>FV</strong> = thamani ya baadaye</li><li><strong>r</strong> = kiwango cha riba (discount rate)</li><li><strong>n</strong> = idadi ya miaka</li></ul><p>Mfano: TZS 1,000,000 utakaopata baada ya miaka 3, kiwango cha riba 10% — thamani yake leo ni: 1,000,000 ÷ (1.10)³ = TZS 751,315</p><h3>Future Value (FV)</h3><p>FV = PV × (1 + r)<sup>n</sup></p><p>Mfano: Ukiweka TZS 500,000 leo kwa riba ya 8% kwa miaka 5 — utapata: 500,000 × (1.08)⁵ = TZS 734,664</p><h3>Annuities</h3><p>Annuity ni malipo sawa yanayofanywa kwa vipindi vya kawaida. PV ya annuity = PMT × [1-(1+r)<sup>-n</sup>] ÷ r</p><blockquote><strong>Mfano wa Tanzania:</strong> Benki ya NMB inatoa mkopo wa nyumba — unalipa TZS 500,000 kwa mwezi kwa miaka 20. Je, jumla unayolipa ni ngapi zaidi ya kiasi ulichokopa? TVM inakuambia hilo!</blockquote>');
        $this->assignment($l, 'TVM Calculations kwa Excel', 'Tumia Excel kuhesabu: (1) Ukiweka TZS 2,000,000 kwa riba 12% p.a., utakuwa na kiasi gani baada ya miaka 10? (2) Kampuni itapata TZS 50,000,000 baada ya miaka 5. Discount rate ni 15%. Thamani yake leo ni ngapi? (3) Unataka kuokoa TZS 10,000,000 kwa miaka 3. Unahitaji kuweka kiasi gani kila mwezi kwa riba ya 8% p.a.? Onyesha calculations zote. Wasilisha .xlsx', 60, 7);

        $l = $this->lesson($m1, 2, 'Net Present Value (NPV) na Internal Rate of Return (IRR)', 'Jinsi ya kuamua kama uwekezaji ni mzuri au la kwa kutumia NPV na IRR. Mifano halisi ya miradi ya biashara.', 1500,
            [['video_youtube', 'NPV and IRR Explained', 'https://www.youtube.com/watch?v=QFOA9uDvn4s']],
            '<h2>NPV na IRR — Vigezo vya Uamuzi wa Uwekezaji</h2><p>NPV na IRR ni zana kuu mbili za kutathmini uwekezaji wa muda mrefu. Zinakusaidia kuamua: "Je, ninapaswa kuwekeza katika mradi huu?"</p><h3>Net Present Value (NPV)</h3><p>NPV = Jumla ya (Cash Flow_t ÷ (1+r)<sup>t</sup>) - Initial Investment</p><ul><li><strong>NPV &gt; 0:</strong> Mradi unaongeza thamani — WEKEZA</li><li><strong>NPV &lt; 0:</strong> Mradi unapoteza thamani — USIOWEKEZE</li><li><strong>NPV = 0:</strong> Mradi unalipa tu gharama ya mtaji</li></ul><p>Katika Excel: <code>=NPV(rate, value1:value_n) - initial_investment</code></p><h3>Internal Rate of Return (IRR)</h3><p>IRR ni kiwango cha riba kinachofanya NPV kuwa sifuri. Ukiwa IRR &gt; Cost of Capital → wekeza.</p><p>Katika Excel: <code>=IRR(values)</code></p><h3>Mfano wa Mradi</h3><p>Kampuni inawekeza TZS 100M leo. Cash flows: Mwaka 1: 30M, Mwaka 2: 40M, Mwaka 3: 50M, Mwaka 4: 40M. Discount rate: 12%.</p><ul><li>NPV = TZS 23.9M (wekeza!)</li><li>IRR = 24.9% (zaidi ya 12% — nzuri)</li></ul><blockquote><strong>Mtego wa IRR:</strong> IRR inaweza kupotosha kwa miradi yenye cash flows zisizo za kawaida (negative baada ya positive). Tumia NPV kama kigezo kikuu.</blockquote>');
        $this->assignment($l, 'Tathmini ya Mradi wa Uwekezaji', 'Kampuni ya Dar es Salaam inazingatia kufungua tawi jipya. Gharama ya awali: TZS 500,000,000. Cash flows zinazotarajiwa: Mwaka 1-5: TZS 120M, 150M, 180M, 160M, 140M. Discount rate: 14%. Hesabu: NPV, IRR, Payback Period. Je, unapendekeza nini na kwa nini? Onyesha kazi zote katika Excel. Wasilisha .xlsx + maelezo mafupi (Word au PDF).', 80, 10);

        $l = $this->lesson($m1, 3, 'Capital Structure na WACC', 'Je, kampuni inapaswa kutumia debt au equity? Jifunza jinsi ya kupanga mchanganyiko bora wa mtaji na kuhesabu Weighted Average Cost of Capital.', 1440,
            [['video_youtube', 'WACC Explained Simply', 'https://www.youtube.com/watch?v=0FTSS0MVf1c']],
            '<h2>Capital Structure na WACC</h2><p><strong>Capital Structure</strong> ni jinsi kampuni inavyofadhili shughuli zake — mchanganyiko wa deni (debt) na hisa (equity). Kila chanzo kina gharama yake.</p><h3>Gharama ya Debt (Cost of Debt)</h3><p>Kd = Riba × (1 - Kiwango cha Kodi)</p><p>Mfano: Mkopo kwa riba 15%, kodi 30% → Kd = 15% × (1-0.30) = 10.5%</p><h3>Gharama ya Equity (Cost of Equity) — CAPM</h3><p>Ke = Rf + β × (Rm - Rf)</p><ul><li><strong>Rf</strong> = Risk-free rate (kawaida T-bills — Tanzania ~8%)</li><li><strong>β</strong> = Beta (hatari ya kampuni dhidi ya soko)</li><li><strong>Rm - Rf</strong> = Market risk premium (~5-7%)</li></ul><h3>WACC</h3><p>WACC = (E/V × Ke) + (D/V × Kd × (1-T))</p><ul><li>E = thamani ya equity, D = thamani ya deni, V = E + D</li></ul><p>Mfano: Equity 60%, Ke=16%; Debt 40%, Kd=10.5% → WACC = (0.6×16%) + (0.4×10.5%) = 13.8%</p><blockquote><strong>Umuhimu:</strong> WACC ndio discount rate unayotumia kwa NPV calculations. Kampuni nzuri zinalenga kupunguza WACC — inamaanisha mtaji wenye bei nafuu zaidi.</blockquote>');
        $this->assignment($l, 'WACC Calculation ya Kampuni', 'Kampuni ina: Equity TZS 800M (Ke=18%), Deni TZS 200M (riba 12%, kodi 30%). (1) Hesabu WACC. (2) Mradi mpya una NPV ya TZS 50M kwa discount rate ya 15%. Je, unapaswa kukubali? Kwa nini? (3) Ikiwa kampuni inaongeza deni hadi 50% ya mtaji, WACC itabadilika vipi? Onyesha hesabu zote. Wasilisha .xlsx', 70, 10);

        // MODULE 2
        $m2 = $this->mod($c, 2, 'Financial Modelling na Valuation', 'Tengeneza mifano ya kifedha inayotumika katika benki, mashirika ya uwekezaji, na makampuni makubwa.');

        $l = $this->lesson($m2, 1, 'Three-Statement Financial Model', 'Jinsi ya kuunda mfano unaounganisha Income Statement, Balance Sheet, na Cash Flow Statement. Inahusika kwa kila mhasibu na mfanyabiashara wa hali ya juu.', 2400,
            [['video_youtube', 'Three Statement Model Tutorial', 'https://www.youtube.com/watch?v=p5C5_h3MxBo']],
            '<h2>Three-Statement Financial Model</h2><p>Three-Statement Model ni msingi wa financial modelling yote. Inaunganisha hati tatu za fedha kwa njia ambayo mabadiliko katika moja yanaathiri nyingine zote moja kwa moja.</p><h3>Muundo wa Model</h3><ol><li><strong>Income Statement (P&L):</strong> Mapato, gharama, faida — inaonyesha utendaji</li><li><strong>Balance Sheet:</strong> Mali, madeni, hisa — inaonyesha hali ya mali</li><li><strong>Cash Flow Statement:</strong> Pesa halisi inayoingia na kutoka — inaonyesha ukweli</li></ol><h3>Jinsi Zinavyounganika</h3><ul><li>Net Income kutoka P&L → Equity kwenye Balance Sheet (kwa retained earnings)</li><li>Net Income kutoka P&L → Operating Cash Flow (mwanzo wa CF Statement)</li><li>Cash mwishoni mwa CF Statement → Cash kwenye Balance Sheet</li><li>D&A kutoka P&L → Balance Sheet (accumulated depreciation) na CF Statement (add-back)</li></ul><h3>Hatua za Kuunda</h3><ol><li>Ingiza historical data (miaka 3-5 iliyopita)</li><li>Weka assumptions kwa projection (ukuaji wa mapato, margin targets, nk)</li><li>Unda P&L projection kwa formulas</li><li>Tengeneza Balance Sheet kulingana na P&L</li><li>CF Statement inajaza mwenyewe ukikamilisha hizi mbili</li></ol><blockquote><strong>Kumbuka:</strong> Balance Sheet lazima i-balance (Assets = Liabilities + Equity). Hii ndiyo ukaguzi wa kwanza wa kujua model yako ina makosa au la.</blockquote>');
        $this->assignment($l, 'Financial Model ya Kampuni Ndogo', 'Chagua biashara halisi (au imaginary) na uunde Three-Statement Model kwa miaka 3 ya kweli na projection ya miaka 3. Assumptions zako: ukuaji wa mapato 15% p.a., gross margin 40%, operating expenses 25% ya mapato. Onyesha: P&L, Balance Sheet, Cash Flow Statement — zote zikiunganika. Wasilisha .xlsx na maelezo ya assumptions. 100 marks, wiki 2.', 100, 14);

        $l = $this->lesson($m2, 2, 'DCF Valuation — Thamani ya Kampuni', 'Discounted Cash Flow (DCF) ni njia ya kawaida ya kutathmini thamani ya kampuni. Inayotumika na investment banks, PE firms, na M&A advisors duniani.', 1800,
            [['video_youtube', 'DCF Valuation Step by Step', 'https://www.youtube.com/watch?v=fd_emLLzJnk']],
            '<h2>DCF Valuation</h2><p>DCF Valuation inakuambia thamani ya kampuni leo kulingana na pesa itakayozalisha siku zijazo. Ni msingi wa uamuzi wa kununua au kuuza biashara.</p><h3>Hatua za DCF</h3><ol><li><strong>Project Free Cash Flows (FCF):</strong> FCF = EBIT(1-T) + D&A - CapEx - ΔNWC</li><li><strong>Amua Terminal Value:</strong> TV = FCF_n × (1+g) ÷ (WACC - g) — Gordon Growth Model</li><li><strong>Discount kwa WACC:</strong> PV ya FCF + PV ya Terminal Value</li><li><strong>Hesabu Enterprise Value:</strong> EV = PV ya FCFs + PV ya TV</li><li><strong>Hesabu Equity Value:</strong> Equity Value = EV - Net Debt</li><li><strong>Hesabu Price per Share:</strong> Equity Value ÷ Shares Outstanding</li></ol><h3>Sensitivity Analysis</h3><p>DCF ni nyeti sana kwa assumptions. Fanya sensitivity table (Data Table kwenye Excel) kuonyesha thamani inavyobadilika na WACC na growth rate.</p><blockquote><strong>Makosa ya Kawaida:</strong> (1) Terminal value inakuwa 70-80% ya EV — usidharau assumptions za growth. (2) Kuchanganya nominal na real cash flows. (3) Kusahau kutoa net debt kupata equity value.</blockquote>');
        $this->assignment($l, 'DCF Valuation ya Biashara', 'Chagua kampuni ya Tanzania (Vodacom Tanzania, CRDB, Simba Cement, au nyingine yoyote) au tumia data ya kufikirika. Tengeneza DCF Valuation kamili: FCF projections miaka 5, Terminal Value, WACC calculation, Enterprise Value, na Equity Value per share. Onyesha sensitivity table kwa WACC ±2% na growth ±1%. Je, hisa imepimwa vizuri au la? Wasilisha .xlsx + ripoti fupi. 100 marks, wiki 2.', 100, 14);

        // MODULE 3
        $m3 = $this->mod($c, 3, 'Working Capital na Cash Flow Management', 'Usimamie pesa za kila siku za biashara — hii ndiyo inayoua biashara nyingi hata zenye faida nzuri.');

        $l = $this->lesson($m3, 1, 'Cash Conversion Cycle (CCC)', 'Ni siku ngapi biashara inachukua kugeuka malighafi kuwa pesa taslimu. Kupunguza CCC kunamaanisha pesa zaidi kwa biashara.', 1200,
            [['video_youtube', 'Cash Conversion Cycle Explained', 'https://www.youtube.com/watch?v=gMqLCDjN3_M']],
            '<h2>Cash Conversion Cycle (CCC)</h2><p>CCC = DIO + DSO - DPO</p><ul><li><strong>DIO (Days Inventory Outstanding):</strong> Muda wanaochukua stock kukaa kabla ya kuuzwa</li><li><strong>DSO (Days Sales Outstanding):</strong> Muda wanaochukua wateja kulipa baada ya kuuziwa</li><li><strong>DPO (Days Payable Outstanding):</strong> Muda wanaochukua biashara kulipa wasambazaji</li></ul><h3>Jinsi ya Kuboresha CCC</h3><ul><li>Punguza DIO: Simamia inventory vizuri, tumia Just-in-Time</li><li>Punguza DSO: Toa incentives za kulipa mapema, fanya credit checks</li><li>Ongeza DPO: Jadili masharti mazuri na wasambazaji</li></ul><p>Mfano: Duka la bidhaa: DIO=45, DSO=30, DPO=20 → CCC = 45+30-20 = 55 siku</p><blockquote><strong>Ukweli wa Biashara:</strong> Amazon ina CCC hasi — wanakusanya pesa kutoka wateja kabla ya kulipa wasambazaji. Hii ndio nguvu yao ya kweli ya kifedha!</blockquote>');
        $this->assignment($l, 'CCC Analysis ya Biashara Yako', 'Tumia data halisi au ya kufikirika ya biashara moja. Hesabu: DIO, DSO, DPO, na CCC. Linganisha na wastani wa sekta. Toa mapendekezo 3 ya kuboresha CCC. Onyesha athari ya mapendekezo yako kwa cash flow ya mwaka mzima. Wasilisha .xlsx + ripoti (1 ukurasa). 50 marks, siku 7.', 50, 7);
    }

    // =========================================================================
    // COURSE 2 — IFRS Standards & Financial Reporting
    // =========================================================================
    private function ifrsCourse(int $tid): void
    {
        $c = Course::updateOrCreate(
            ['title' => 'IFRS Standards & Financial Reporting: Mwongozo wa Vitendo'],
            [
                'description'    => 'Elewa na utumie IFRS (International Financial Reporting Standards) katika mazingira ya Tanzania na Afrika Mashariki. Course hii inafunika viwango muhimu: IFRS 15, IFRS 16, IFRS 9, IAS 36, IAS 38 — kwa mifano halisi ya biashara za kikanda.',
                'category'       => 'ifrs',
                'level'          => 'intermediate',
                'duration_hours' => 20,
                'price_tzs'      => 0,
                'instructor_id'  => $tid,
                'created_by'     => $tid,
                'status'         => 'pending_approval',
            ]
        );

        $m1 = $this->mod($c, 1, 'Mfumo wa IFRS na Kanuni za Msingi', 'Jifunza mfumo mkuu wa IFRS, tofauti na GAAP, na kanuni za msingi za uandishi wa hati za fedha.');

        $l = $this->lesson($m1, 1, 'Utangulizi wa IFRS na Conceptual Framework', 'IFRS ni nini? Kwa nini Tanzania inahitaji IFRS? Jifunza Conceptual Framework — msingi wa viwango vyote.', 900,
            [['video_youtube', 'Introduction to IFRS', 'https://www.youtube.com/watch?v=_tRBRu5XPEY']],
            '<h2>IFRS — Mwongozo wa Vitendo</h2><p><strong>IFRS (International Financial Reporting Standards)</strong> ni viwango vya kimataifa vya uandishi wa hati za fedha vilivyoundwa na IASB (International Accounting Standards Board). Zaidi ya nchi 140 zinatumia IFRS.</p><h3>Kwa Nini IFRS?</h3><ul><li>Inawezesha ulinganisho wa hati za fedha duniani</li><li>Inaongeza uwazi na uaminifu kwa wawekezaji</li><li>Tanzania Capital Markets na benki za kimataifa zinahitaji IFRS</li><li>NSE (Nairobi) na DSE zinahitaji IFRS kwa makampuni yaliyoorodheshwa</li></ul><h3>Tofauti Muhimu: IFRS vs GAAP</h3><ul><li><strong>IFRS:</strong> Principles-based — inakupa kanuni na unaamua jinsi ya kutumia</li><li><strong>US GAAP:</strong> Rules-based — kuna sheria nyingi za kufuata</li><li><strong>IFRS:</strong> Inaruhusu revaluation ya mali; GAAP mara nyingi haitoi ruhusa</li><li><strong>IFRS:</strong> LIFO hairuhusiwi; GAAP inaruhusu</li></ul><h3>Conceptual Framework</h3><p>Inafafanua: lengo la hati za fedha, sifa za ubora (uwakilishi wa uaminifu, umuhimu), dhana za kipimo, na vipengele (mali, madeni, hisa, mapato, gharama).</p><blockquote><strong>Sifa Nne za Ubora:</strong> Relevance, Faithful Representation, Comparability, na Understandability. Hati nzuri ya fedha lazima ikidhi hizi zote.</blockquote>');
        $this->assignment($l, 'IFRS vs Local Standards Research', 'Tafiti tofauti kuu 5 kati ya IFRS na Tanzania Accounting Standards (TAS) au GAAP. Kwa kila tofauti: (1) Eleza kwa undani, (2) Toa mfano wa jinsi inavyoathiri ripoti za fedha, (3) Sema ni ipi ni bora kwa biashara ya Tanzania na kwa nini. Wasilisha Word/PDF, kurasa 3-5. 40 marks, siku 5.', 40, 5);

        $l = $this->lesson($m1, 2, 'IFRS 15 — Mapato Kutoka kwa Wateja', 'Kiwango kipya kinachobadilisha jinsi tunavyotambua mapato. Mifano: mikataba ya ujenzi, lishe za software, na mauzo ya bidhaa.', 1440,
            [['video_youtube', 'IFRS 15 Revenue Recognition', 'https://www.youtube.com/watch?v=8Adf9Yf1QgE']],
            '<h2>IFRS 15 — Mapato Kutoka kwa Wateja</h2><p>IFRS 15 ilibadilisha jinsi kampuni zinavyotambua mapato (revenue recognition). Inafuata mfumo wa hatua 5.</p><h3>Mfumo wa Hatua 5 (5-Step Model)</h3><ol><li><strong>Tambua Mkataba:</strong> Je, kuna mkataba unaohusisha haki na wajibu?</li><li><strong>Tambua Performance Obligations:</strong> Kampuni inalazimika kufanya nini hasa?</li><li><strong>Amua Bei ya Mkataba:</strong> Jumla ya malipo unayotarajia kupata</li><li><strong>Gawanya Bei:</strong> Kama kuna obligations nyingi, gawanya bei kwa kila moja</li><li><strong>Tambua Mapato:</strong> Pokea mapato unapotimiza kila obligation</li></ol><h3>Mifano ya Tanzania</h3><ul><li><strong>Kampuni ya Ujenzi:</strong> Mkataba wa nyumba — mapato yanakusanywa hatua kwa hatua (over time)</li><li><strong>Simu (Airtel/Vodacom):</strong> Miaka ya mkataba — mapato yanagawanywa kwa muda wote wa mkataba</li><li><strong>Usafirishaji (DHL):</strong> Huduma inayotolewa wakati wa delivery — mapato wakati wa delivery</li></ul><blockquote><strong>Mabadiliko Makubwa:</strong> Kabla ya IFRS 15, makampuni mengi yalitambua mapato wakati wa saini ya mkataba. IFRS 15 inasema: tambua tu unapotimiza wajibu wako.</blockquote>');
        $this->assignment($l, 'IFRS 15 Application Cases', 'Tathmini matukio matatu haya kwa IFRS 15 — kila moja eleza hatua 5: (1) Kampuni ya IT inauza software license + installation + support kwa TZS 50M kwa miaka 3. (2) Kampuni ya ujenzi inasaini mkataba wa TZS 2B, ujenzi unachukua miaka 2. Mwaka wa kwanza 40% imekamilika. (3) Duka la rejareja linatoa loyalty points — kwa kila TZS 1,000 ya ununuzi mteja anapata points za TZS 100. Wasilisha Word/PDF. 60 marks, siku 7.', 60, 7);

        $l = $this->lesson($m1, 3, 'IFRS 16 — Mikataba ya Kukodisha (Leases)', 'Mabadiliko makubwa: sasa kampuni lazima iweke mali na madeni ya mikataba yote ya kukodisha (leases) kwenye Balance Sheet.', 1440,
            [['video_youtube', 'IFRS 16 Leases Explained', 'https://www.youtube.com/watch?v=sBxEEMpVtPc']],
            '<h2>IFRS 16 — Mikataba ya Kukodisha</h2><p>IFRS 16 (ilianza 2019) ilibadilisha accounting ya leases kabisa. Awali, operating leases hazikuonekana kwenye Balance Sheet. Sasa, leases zote za muda mrefu (zaidi ya mwaka 1 na thamani ya chini ya $5,000) lazima ziandikwe.</p><h3>Athari kwa Balance Sheet</h3><ul><li><strong>Mali:</strong> Right-of-Use Asset (ROU) — thamani ya haki ya kutumia mali iliyokodishwa</li><li><strong>Madeni:</strong> Lease Liability — madeni ya malipo yaliyobaki</li></ul><h3>Kwa Mlipwa wa Kodi (Lessee)</h3><ol><li>Pima Lease Liability = PV ya malipo yajayo (discount rate = incremental borrowing rate)</li><li>ROU Asset = Lease Liability + initial direct costs + upfront payments</li><li>Kila mwaka: Amortize ROU Asset (depreciation) + Interest expense kwenye Lease Liability</li></ol><h3>Exemptions</h3><ul><li>Short-term leases (chini ya mwaka 1) — inaweza kuchukuliwa kama expense moja kwa moja</li><li>Low-value assets (chini ya USD 5,000) — inaweza kuchukuliwa kama expense</li></ul><blockquote><strong>Athari za Kibiashara:</strong> Makampuni ya ndege (Kenya Airways, Ethiopian), duka kubwa (Carrefour), na hospitali — zote ziliathirika sana. Madeni yao yalionekana kuongezeka ghafla, wakati kwa kweli yalikuwepo — hayakuonekana tu.</blockquote>');
        $this->assignment($l, 'IFRS 16 Lease Accounting', 'Kampuni inasaini mkataba wa kukodisha ofisi kwa miaka 5. Kodi ya kila mwaka: TZS 120,000,000, inalipwa mwanzoni mwa kila mwaka. Incremental borrowing rate: 10% p.a. Hesabu: (1) Lease Liability awali, (2) ROU Asset awali, (3) Amortization schedule ya miaka 5 (Lease Liability na Interest), (4) Depreciation ya ROU Asset, (5) Onyesha viingilio vya journal kwa miaka 2 ya kwanza. Wasilisha .xlsx. 70 marks, siku 10.', 70, 10);

        $m2 = $this->mod($c, 2, 'IFRS 9 na Udhibiti wa Mali za Fedha', 'Jinsi ya kutathmini na kuandika dhamana, mikopo, na mali zingine za fedha kulingana na IFRS 9.');

        $l = $this->lesson($m2, 1, 'IFRS 9 — Expected Credit Loss (ECL) Model', 'Mabadiliko makubwa ya IFRS 9: badala ya kutambua hasara za mikopo baada ya kutokea, sasa lazima uzitarajiwe mapema.', 1500,
            [['video_youtube', 'IFRS 9 Expected Credit Loss', 'https://www.youtube.com/watch?v=K1Jdj1KPuA4']],
            '<h2>IFRS 9 — Expected Credit Loss (ECL)</h2><p>IFRS 9 ilibadilisha jinsi benki na makampuni yanavyotathmini uwezekano wa kutolipwa (credit risk). IAS 39 ya awali ilitumia "incurred loss" — unatambua hasara tu inapotokea. IFRS 9 inatumia "expected loss" — unatambua hatari mapema.</p><h3>Hatua Tatu za ECL</h3><ol><li><strong>Stage 1 (Performing):</strong> Dhamana/mkopo mpya au bila mabadiliko makubwa ya hatari. ECL = 12-month expected loss.</li><li><strong>Stage 2 (Underperforming):</strong> Ongezeko kubwa la hatari ya mkopo tangu awali. ECL = Lifetime expected loss.</li><li><strong>Stage 3 (Non-performing):</strong> Imekwama (default). ECL = Lifetime expected loss, mapato ya riba yanahesabiwa kwa amortised cost tu.</li></ol><h3>Kuhesabu ECL</h3><p>ECL = PD × LGD × EAD</p><ul><li><strong>PD:</strong> Probability of Default — uwezekano wa kutolipwa</li><li><strong>LGD:</strong> Loss Given Default — sehemu ya hasara ukitolipwa</li><li><strong>EAD:</strong> Exposure at Default — kiasi kilichoko hatarini</li></ul><blockquote><strong>Athari kwa Tanzania:</strong> Benki za Tanzania (CRDB, NMB, NBC) zilihitaji kuongeza provisioning kwa kiasi kikubwa baada ya IFRS 9 — ilikuwa na athari kubwa kwa faida zao zilizotangazwa.</blockquote>');
        $this->assignment($l, 'ECL Calculation kwa Mkoba wa Mikopo', 'Benki ina mkoba wa mikopo 4: (1) Mkopo mpya TZS 500M, PD=1%, LGD=40%. (2) Mkopo wa zamani bila shida TZS 300M, PD=2%, LGD=45%. (3) Mkopo wenye matatizo TZS 200M, Lifetime PD=25%, LGD=60%. (4) Mkopo umekwama TZS 100M, LGD=70%. Hesabu ECL ya kila mkopo. Je, ni Stage gani kila mmoja? Jumla ya provision inahitajika ni ngapi? Wasilisha .xlsx. 60 marks, siku 7.', 60, 7);
    }

    // =========================================================================
    // COURSE 3 — ERP Systems: Sage & QuickBooks Mastery
    // =========================================================================
    private function erpCourse(int $tid): void
    {
        $c = Course::updateOrCreate(
            ['title' => 'ERP Systems: Sage & QuickBooks kwa Biashara ya Afrika Mashariki'],
            [
                'description'    => 'Jifunza kutumia mifumo ya ERP inayotumiwa zaidi katika biashara ndogo na za kati Afrika Mashariki — Sage 50 (Pastel) na QuickBooks. Itakusaidia kuongoza hesabu, hisa, malipo ya wafanyakazi, na ripoti za fedha kwa urahisi bila msaada wa mtaalamu wa nje.',
                'category'       => 'erp_systems',
                'level'          => 'beginner',
                'duration_hours' => 16,
                'price_tzs'      => 0,
                'instructor_id'  => $tid,
                'created_by'     => $tid,
                'status'         => 'pending_approval',
            ]
        );

        $m1 = $this->mod($c, 1, 'Utangulizi wa ERP na QuickBooks', 'Jifunza ERP ni nini, mbona biashara zinahitaji ERP, na jinsi ya kuanzisha QuickBooks kwa biashara yako.');

        $l = $this->lesson($m1, 1, 'ERP ni Nini? Historia na Manufaa', 'Elewa mfumo wa ERP — jinsi unavyounganisha idara zote za biashara (hesabu, hisa, HR, mauzo) katika mfumo mmoja.', 720,
            [['video_youtube', 'What is ERP System?', 'https://www.youtube.com/watch?v=dcRBBEFfFiI']],
            '<h2>ERP — Enterprise Resource Planning</h2><p><strong>ERP</strong> ni mfumo wa kompyuta unaounganisha mchakato wote wa biashara — hesabu, hisa, mauzo, ununuzi, HR, na uzalishaji — katika mfumo mmoja unaoshirikiana. Badala ya kuwa na mifumo tofauti isiyozungumzana, ERP inafanya kila kitu kuwa connected.</p><h3>Faida za ERP</h3><ul><li><strong>Habari Moja kwa Wakati Mmoja:</strong> Mauzo yanapoingia, hisa inashuka moja kwa moja</li><li><strong>Ripoti za Haraka:</strong> Unaona hali ya biashara wakati wowote bila kusubiri mkutano</li><li><strong>Kupunguza Makosa:</strong> Data inaingia mara moja tu — si kwa mifumo tofauti</li><li><strong>Ukaguzi Bora:</strong> Kila mabadiliko yanarekodiwa na inaweza kufuatiliwa</li></ul><h3>ERP Maarufu Afrika Mashariki</h3><ul><li><strong>QuickBooks:</strong> Biashara ndogo — rahisi kutumia, bei nafuu</li><li><strong>Sage 50 (Pastel):</strong> Biashara za kati — imara, inayotumiwa zaidi Tanzania/Kenya</li><li><strong>Sage 200/300:</strong> Biashara kubwa — idara nyingi, multi-currency</li><li><strong>SAP:</strong> Makampuni ya kimataifa — gharama kubwa, nguvu kubwa</li><li><strong>Odoo:</strong> Open source — inakua haraka Afrika, rahisi kubinafsisha</li></ul><blockquote><strong>Ukweli wa Soko:</strong> Zaidi ya 70% ya biashara ndogo za Tanzania bado zinatumia Excel au karatasi za mkono. Kujua ERP kunakufanya thamani zaidi soko la kazi.</blockquote>');
        $this->assignment($l, 'ERP Research na Business Case', 'Fanya utafiti wa biashara moja ya Tanzania ambayo imehamia ERP (au inalopanga kuhamia). Andika ripoti inayojumuisha: (1) Biashara ni nini na mfumo gani wa zamani waliotumia, (2) Walitumia ERP gani na kwa nini walichagua hiyo, (3) Faida na changamoto zilizokutana nazo, (4) Gharama ya utekelezaji (kama inapatikana). Kurasa 2-3. Wasilisha Word/PDF. 30 marks, siku 5.', 30, 5);

        $l = $this->lesson($m1, 2, 'Kuanzisha QuickBooks kwa Biashara Yako', 'Unda kampuni mpya, weka chart of accounts, ingiza opening balances, na fanya mipangilio ya msingi ya QuickBooks.', 1500,
            [['video_youtube', 'QuickBooks Setup Tutorial', 'https://www.youtube.com/watch?v=nHZYFvaCCII']],
            '<h2>Kuanzisha QuickBooks — Hatua kwa Hatua</h2><p>QuickBooks ni mfumo maarufu zaidi duniani kwa biashara ndogo. Inaweza kusimamia hesabu, hisa, malipo ya wafanyakazi, na ripoti zote za fedha.</p><h3>Hatua za Awali</h3><ol><li><strong>Unda Kampuni Mpya:</strong> File → New Company → Jibu maswali ya msingi (jina, aina ya biashara, mwaka wa fedha)</li><li><strong>Chart of Accounts:</strong> QuickBooks inatoa chart ya kawaida — ibinafsisha kwa biashara yako. Ongeza accounts za TZS kwa biashara ya Tanzania.</li><li><strong>Opening Balances:</strong> Ingiza hali ya hesabu za awali (mfano: pesa benki, madeni, mali)</li><li><strong>Mipangilio ya Kodi:</strong> Weka VAT (18% Tanzania), withholding tax</li><li><strong>Unda Wateja na Wasambazaji:</strong> Ingiza maelezo ya contacts wa biashara</li></ol><h3>Chart of Accounts ya Tanzania</h3><ul><li>Assets: Fedha Taslimu (1000), Benki — NMB (1010), Benki — CRDB (1020), Stock (1200)</li><li>Liabilities: Malipo ya Kulipa (2000), VAT Inayodaiwa (2100), PAYE Inayodaiwa (2200)</li><li>Equity: Mtaji wa Mmiliki (3000), Faida Zilizohifadhiwa (3100)</li><li>Mapato: Mauzo (4000), Mapato Mengine (4500)</li><li>Gharama: Gharama ya Bidhaa Zilizouzwa (5000), Mishahara (6000), Kodi ya Ofisi (6100)</li></ul><blockquote><strong>Tip ya Kitaalamu:</strong> Kila wakati, weka akaunti za benki kwa mujibu wa benki halisi unazotumia. Reconciliation itakuwa rahisi sana ukifanya hivyo.</blockquote>');
        $this->assignment($l, 'Setup ya QuickBooks Demo', 'Unda kampuni mpya katika QuickBooks (unaweza kutumia trial version). Kwa biashara ya duka la rejareja "Duka Bora Ltd": (1) Ingiza maelezo ya kampuni (jina, anwani ya Dar es Salaam, mwaka wa fedha Jan-Dec), (2) Binafsisha chart of accounts kwa biashara ya rejareja ya Tanzania (angalau accounts 15), (3) Ingiza wateja 5 na wasambazaji 3, (4) Weka opening balances (Benki TZS 5M, Stock TZS 3M, Madeni ya Kulipa TZS 1M). Chukua screenshots za kila hatua. Wasilisha PDF ya screenshots + maelezo. 50 marks, siku 7.', 50, 7);

        $l = $this->lesson($m1, 3, 'Miamala ya Mauzo na Manunuzi katika QuickBooks', 'Ingiza ankara za mauzo, stakabadhi za pesa, ankara za ununuzi, na malipo ya wasambazaji. Jinsi ya kufuatilia madeni.', 1440,
            [['video_youtube', 'QuickBooks Invoicing and Bills', 'https://www.youtube.com/watch?v=sFBEBjbw5B8']],
            '<h2>Miamala ya Mauzo na Manunuzi</h2><p>Miamala ya kila siku — kuuza na kununua — ndiyo msingi wa QuickBooks. Kila muamala unaingia mara moja tu, kisha mfumo unasasisha hesabu zote moja kwa moja.</p><h3>Mzunguko wa Mauzo (Sales Cycle)</h3><ol><li><strong>Estimate:</strong> Kadirio la bei kwa mteja (optional)</li><li><strong>Sales Order:</strong> Oda ya mteja iliyopokelewa (optional)</li><li><strong>Invoice:</strong> Ankara inayotumwa kwa mteja baada ya kumuuzia</li><li><strong>Receive Payment:</strong> Pokea malipo ya mteja dhidi ya invoice</li><li><strong>Deposit:</strong> Weka pesa benki</li></ol><h3>Mzunguko wa Manunuzi (Purchase Cycle)</h3><ol><li><strong>Purchase Order:</strong> Oda inayotumwa kwa msambazaji</li><li><strong>Receive Items:</strong> Pokea bidhaa (inaongeza stock moja kwa moja)</li><li><strong>Enter Bill:</strong> Ingiza ankara ya msambazaji</li><li><strong>Pay Bill:</strong> Lipa msambazaji</li></ol><h3>Mauzo ya Pesa Taslimu</h3><p>Kwa mauzo ya moja kwa moja (duka): Customers → Enter Sales Receipts. Hii inafanya mauzo na kupokea pesa kwa hatua moja tu — bila kutumia invoice.</p><blockquote><strong>Kumbuka Tanzania:</strong> Kwa biashara zinazolazimika kutoa EFD receipts, QuickBooks inaweza kuunganishwa na mfumo wa EFD — angalia msanidi wa QuickBooks wa Tanzania.</blockquote>');
        $this->assignment($l, 'Miamala ya Kweli ya QuickBooks', 'Katika QuickBooks yako ya "Duka Bora Ltd", ingiza miamala hii: (1) Uliuza bidhaa kwa mteja "Hamisi Ali" TZS 450,000 (invoice #001, tarehe ya kwanza ya mwezi). (2) Ulinunua stock kutoka "Wasambazaji WA" TZS 200,000, atalipwa baadaye. (3) Hamisi Ali alilipa nusu ya ankara yake. (4) Uliuza bidhaa za pesa taslimu TZS 85,000. (5) Ulilipa kodi ya mwezi TZS 300,000. Chukua screenshots za kila entry na Account Receivable/Payable report. Wasilisha PDF. 60 marks, siku 7.', 60, 7);

        $m2 = $this->mod($c, 2, 'Ripoti na Uchambuzi wa Fedha katika QuickBooks', 'Tengeneza na tafsiri ripoti za fedha kwa kutumia QuickBooks.');

        $l = $this->lesson($m2, 1, 'Financial Reports za QuickBooks', 'P&L, Balance Sheet, Cash Flow — jinsi ya kuzitoa, kuzielewa, na kuzitumia kufanya maamuzi ya biashara.', 1080,
            [['video_youtube', 'QuickBooks Reports Tutorial', 'https://www.youtube.com/watch?v=3siBpFRr_nw']],
            '<h2>Financial Reports katika QuickBooks</h2><p>Nguvu ya kweli ya QuickBooks iko katika ripoti zake. Kwa klikis chache unaweza kupata picha kamili ya hali ya fedha ya biashara yako.</p><h3>Ripoti Kuu Tatu</h3><h4>1. Profit & Loss (P&L / Income Statement)</h4><p>Reports → Company & Financial → Profit & Loss Standard</p><ul><li>Inaonyesha mapato, gharama, na faida/hasara kwa kipindi fulani</li><li>Unaweza kuchagua mwezi, robo, au mwaka mzima</li><li>Unaweza kulinganisha vipindi (mwaka huu vs mwaka uliopita)</li></ul><h4>2. Balance Sheet</h4><p>Reports → Company & Financial → Balance Sheet Standard</p><ul><li>Inaonyesha mali, madeni, na hisa kwa tarehe fulani</li><li>Lazima i-balance: Assets = Liabilities + Equity</li></ul><h4>3. Statement of Cash Flows</h4><p>Reports → Company & Financial → Statement of Cash Flows</p><ul><li>Inaonyesha pesa halisi iliyoingia na kutoka — hii ndiyo kweli ya biashara</li></ul><h3>Ripoti Muhimu Zingine</h3><ul><li><strong>A/R Aging:</strong> Wateja wanaodaiwa na kwa muda gani</li><li><strong>A/P Aging:</strong> Wasambazaji unaodaiwa na kwa muda gani</li><li><strong>Sales by Customer:</strong> Wateja wanaoleta mapato zaidi</li><li><strong>Inventory Valuation:</strong> Thamani ya hisa yako sasa hivi</li></ul><blockquote><strong>Kawaida Nzuri:</strong> Angalia ripoti hizi tatu kila mwisho wa mwezi. Dakika 30 kila mwezi inaweza kuokoa biashara yako.</blockquote>');
        $this->assignment($l, 'Uchambuzi wa Ripoti za Fedha', 'Kutumia QuickBooks yako ya "Duka Bora Ltd", ingiza data ya miamala 20+ ya mwezi mmoja (ubunifu wako). Kisha toa na uchambue: (1) P&L ya mwezi — biashara ina faida au hasara? (2) Balance Sheet — mali zinazidi madeni? (3) A/R Aging — kuna wateja walio nyuma zaidi ya siku 30? (4) Toa mapendekezo 3 ya kuboresha biashara kulingana na ripoti. Wasilisha screenshots za ripoti + PDF ya uchambuzi. 70 marks, siku 10.', 70, 10);
    }

    // =========================================================================
    // COURSE 4 — Advanced Data Analytics with SQL & Excel
    // =========================================================================
    private function analyticsCourse(int $tid): void
    {
        $c = Course::updateOrCreate(
            ['title' => 'Advanced Data Analytics: SQL, Excel na Power BI kwa Uamuzi wa Biashara'],
            [
                'description'    => 'Jifunza kutumia data kuongoza maamuzi ya biashara. Course hii inafundisha SQL kwa uchanganuzi wa hifadhidata, Excel ya hali ya juu, na Power BI kwa dashboards za kitaalamu. Mifano yote inatoka biashara za Tanzania na Afrika Mashariki.',
                'category'       => 'data_analytics',
                'level'          => 'advanced',
                'duration_hours' => 28,
                'price_tzs'      => 0,
                'instructor_id'  => $tid,
                'created_by'     => $tid,
                'status'         => 'pending_approval',
            ]
        );

        $m1 = $this->mod($c, 1, 'SQL kwa Uchanganuzi wa Data', 'Jifunza lugha ya SQL kuomba na kuchambua data kutoka hifadhidata kubwa.');

        $l = $this->lesson($m1, 1, 'Misingi ya SQL: SELECT, WHERE, ORDER BY', 'Jifunza jinsi ya kuomba data kutoka hifadhidata kwa kutumia SQL queries za msingi.', 1440,
            [['video_youtube', 'SQL Tutorial for Beginners', 'https://www.youtube.com/watch?v=7GVFYt6_ZFM']],
            '<h2>SQL — Structured Query Language</h2><p>SQL ndio lugha ya kawaida ya kuwasiliana na hifadhidata za uhusiano (relational databases). Databases kama MySQL, PostgreSQL, SQL Server, na SQLite zote zinatumia SQL.</p><h3>Muundo wa Msingi wa SELECT</h3><pre><code>SELECT column1, column2\nFROM table_name\nWHERE condition\nORDER BY column ASC/DESC\nLIMIT 10;</code></pre><h3>Mifano ya Kweli</h3><p>Fikiria database ya benki ya CRDB na tables: customers, accounts, transactions.</p><pre><code>-- Wateja wote wa Dar es Salaam\nSELECT first_name, last_name, phone\nFROM customers\nWHERE city = \'Dar es Salaam\'\nORDER BY last_name;\n\n-- Miamala 10 ya hivi karibuni zaidi ya TZS 1,000,000\nSELECT transaction_id, amount, transaction_date\nFROM transactions\nWHERE amount > 1000000\nORDER BY transaction_date DESC\nLIMIT 10;</code></pre><h3>Operators Muhimu katika WHERE</h3><ul><li><code>= , &lt;&gt; , &lt; , &gt; , &lt;= , &gt;=</code> — ulinganisho wa kawaida</li><li><code>BETWEEN 100 AND 500</code> — kati ya nambari mbili</li><li><code>LIKE \'Hamisi%\'</code> — inaanza na "Hamisi"</li><li><code>IN (\'Dar\', \'Mwanza\', \'Arusha\')</code> — mojawapo ya orodha</li><li><code>IS NULL / IS NOT NULL</code> — bila thamani / yenye thamani</li></ul><blockquote><strong>Zana za Mazoezi:</strong> Tumia DB Browser for SQLite (bure) au SQLiteOnline.com — unaweza kufanya mazoezi bila kuinstall chochote.</blockquote>');
        $this->assignment($l, 'SQL Queries za Msingi', 'Pewa database ya mauzo (itaambatanishwa — inajumuisha tables: products, customers, orders, order_items). Andika SQL queries: (1) Orodhesha bidhaa zote zenye bei zaidi ya TZS 50,000 (panga bei kushuka), (2) Wateja wote kutoka Dar es Salaam na Mwanza, (3) Amri 5 za hivi karibuni zaidi (tarehe), (4) Bidhaa ambazo stock yake ni chini ya 10, (5) Wateja ambao hawana barua pepe iliyoingizwa. Wasilisha SQL file (.sql) na screenshots za matokeo. 50 marks, siku 5.', 50, 5);

        $l = $this->lesson($m1, 2, 'SQL Aggregate Functions na GROUP BY', 'Hesabu jumla, wastani, na kuhesabu rekodi kwa kutumia GROUP BY. Msingi wa ripoti za biashara kwa SQL.', 1440,
            [['video_youtube', 'SQL GROUP BY and Aggregate Functions', 'https://www.youtube.com/watch?v=M-55BmjOuXY']],
            '<h2>Aggregate Functions na GROUP BY</h2><p>Aggregate functions zinafanya hesabu kwa kikundi cha rekodi — badala ya kurudisha rekodi moja kwa moja, zinazichanganya na kutoa matokeo moja kwa kila kundi.</p><h3>Aggregate Functions Muhimu</h3><ul><li><code>COUNT(*)</code> — Hesabu rekodi</li><li><code>SUM(column)</code> — Jumla ya nambari</li><li><code>AVG(column)</code> — Wastani</li><li><code>MAX(column)</code> — Kubwa zaidi</li><li><code>MIN(column)</code> — Ndogo zaidi</li></ul><h3>GROUP BY</h3><pre><code>-- Jumla ya mauzo kwa kila mkoa\nSELECT region, SUM(amount) AS total_sales, COUNT(*) AS transactions\nFROM orders\nGROUP BY region\nORDER BY total_sales DESC;\n\n-- Wateja wanaolipa zaidi kwa wastani\nSELECT customer_id, AVG(amount) AS avg_purchase\nFROM orders\nGROUP BY customer_id\nHAVING AVG(amount) > 100000\nORDER BY avg_purchase DESC;</code></pre><h3>HAVING vs WHERE</h3><ul><li><code>WHERE</code> inachuja rekodi KABLA ya GROUP BY</li><li><code>HAVING</code> inachuja vikundi BAADA ya GROUP BY</li></ul><blockquote><strong>Mfano wa Kweli:</strong> Unataka kujua bidhaa gani zinauzwa zaidi kila mwezi, na mkoa gani unaleta mapato zaidi — SQL + GROUP BY inakupa jibu hilo kwa sekunde moja, hata kwa data ya miaka 5.</blockquote>');
        $this->assignment($l, 'SQL Analytics Report', 'Kutumia database ile ile ya mauzo, unda ripoti ya SQL inayojibu: (1) Jumla na wastani wa mauzo kwa kila mwezi wa mwaka uliopita. (2) Top 5 bidhaa zinazouza zaidi (kwa quantity). (3) Top 5 wateja kwa thamani ya ununuzi wao wote. (4) Mkoa unaoleta mapato zaidi — na ulio chini zaidi. (5) Bidhaa ambazo hazijawahi kuuzwa (LEFT JOIN). Wasilisha .sql file + screenshots. 70 marks, siku 7.', 70, 7);

        $l = $this->lesson($m1, 3, 'SQL JOINs — Kuunganisha Tables', 'Data halisi ipo katika tables nyingi. Jifunza INNER JOIN, LEFT JOIN, na jinsi ya kuchanganya data kutoka tables tofauti.', 1800,
            [['video_youtube', 'SQL Joins Explained', 'https://www.youtube.com/watch?v=9yeOJ0ZMUYw']],
            '<h2>SQL JOINs — Nguvu ya Uhusiano</h2><p>Databases nzuri zinagawanya data katika tables nyingi zinazohusiana. JOINs zinakuruhusu kuchanganya tables hizi kwa mazao ya maana.</p><h3>Aina za JOINs</h3><h4>INNER JOIN</h4><p>Inarudisha rekodi ambazo zina mechi katika tables ZOTE MBILI.</p><pre><code>SELECT c.first_name, c.last_name, o.order_date, o.total_amount\nFROM customers c\nINNER JOIN orders o ON c.customer_id = o.customer_id\nWHERE o.total_amount > 500000;</code></pre><h4>LEFT JOIN</h4><p>Inarudisha rekodi ZOTE kutoka table ya kushoto, hata kama hazina mechi.</p><pre><code>-- Wateja WOTE — hata wale ambao hawajanunua kamwe\nSELECT c.first_name, c.last_name, COUNT(o.order_id) AS num_orders\nFROM customers c\nLEFT JOIN orders o ON c.customer_id = o.customer_id\nGROUP BY c.customer_id, c.first_name, c.last_name\nORDER BY num_orders DESC;</code></pre><h4>Multiple JOINs</h4><pre><code>SELECT c.first_name, p.product_name, oi.quantity, oi.unit_price\nFROM customers c\nJOIN orders o ON c.customer_id = o.customer_id\nJOIN order_items oi ON o.order_id = oi.order_id\nJOIN products p ON oi.product_id = p.product_id\nWHERE o.order_date >= \'2024-01-01\';</code></pre><blockquote><strong>Kanuni ya Dhahabu:</strong> Kuelewa JOINs kunamaanisha kuelewa muundo wa database. Kabla ya kuandika JOIN, chora diagram ya uhusiano kati ya tables — itakuokoa masaa mengi ya kuchanganyikiwa.</blockquote>');
        $this->assignment($l, 'Complex SQL Analysis kwa JOINs', 'Unda ripoti ngumu ya biashara kwa kutumia JOINs nyingi: (1) Orodha ya miamala yote na jina la mteja, bidhaa, na kiasi — kwa mwezi wa hivi karibuni. (2) Wafanyakazi (sales reps) wanaofanya mauzo zaidi — kwa kutumia employees, orders, order_items tables. (3) Category za bidhaa zenye mapato makubwa zaidi kwa kila mkoa. (4) Wateja ambao wamenunua katika miaka 2 lakini hawajawahi kurudi tangu miezi 6. Wasilisha .sql + maelezo ya kila query. 80 marks, siku 10.', 80, 10);

        $m2 = $this->mod($c, 2, 'Power BI kwa Dashboards za Kitaalamu', 'Unda dashboards zinazobadilika na Power BI — zana inayotumiwa na makampuni makubwa duniani.');

        $l = $this->lesson($m2, 1, 'Power BI: Kutoka Data hadi Dashboard katika Saa Moja', 'Jifunza Power BI Desktop — kuingiza data, kuisafisha kwa Power Query, kuunda measures kwa DAX, na kuunda dashboard ya kwanza.', 2400,
            [['video_youtube', 'Power BI Full Tutorial for Beginners', 'https://www.youtube.com/watch?v=TmhQCQr_ATc']],
            '<h2>Power BI — Business Intelligence kwa Kila Mtu</h2><p>Power BI ni zana ya Microsoft ya business intelligence — inakuruhusu kuchanganya data kutoka vyanzo vingi, kuisafisha, na kuunda dashboards za kitaalamu zinazobadilika kwa wakati halisi.</p><h3>Sehemu Kuu za Power BI</h3><ul><li><strong>Power Query:</strong> Kuingiza na kusafisha data (ETL)</li><li><strong>Data Model:</strong> Kuunda uhusiano kati ya tables</li><li><strong>DAX:</strong> Data Analysis Expressions — lugha ya formulas</li><li><strong>Report View:</strong> Kuunda visuals na dashboards</li></ul><h3>Hatua za Kwanza</h3><ol><li>Get Data → Excel, CSV, SQL Server, Web, au chanzo kingine chochote</li><li>Transform Data → Power Query inafunguka — safisha na panga data</li><li>Close & Apply → data iko tayari</li><li>Unda relationships (Model view) — unganisha tables</li><li>Unda visuals — buruta fields kwenye canvas</li></ol><h3>Visuals Muhimu</h3><ul><li><strong>Bar/Column Chart:</strong> Kulinganisha kategoria</li><li><strong>Line Chart:</strong> Mwelekeo kwa muda</li><li><strong>Map Visual:</strong> Data ya kijiografia — nchi, mikoa, miji</li><li><strong>Card:</strong> KPI moja kwa ukubwa — Jumla ya Mauzo, Wateja, nk</li><li><strong>Slicer:</strong> Filter inayobadilisha visuals vyote kwa wakati mmoja</li></ul><blockquote><strong>Bora ya Power BI:</strong> Ukibonyeza bar moja kwenye chart, visuals vyote vingine vinabadilika moja kwa moja kuonyesha data inayohusiana na uchaguzi wako. Hii inaitwa cross-filtering.</blockquote>');
        $this->assignment($l, 'Sales Dashboard ya Power BI', 'Pewa dataset ya mauzo ya Tanzania (Excel/CSV — itaombwa kutoka kwa mwalimu). Unda Power BI dashboard inayoonyesha: (1) KPI cards — Jumla ya Mauzo, Wateja, Bidhaa, Faida, (2) Line chart ya mwelekeo wa mauzo kwa mwezi, (3) Map ya Tanzania ikionyesha mauzo kwa mkoa, (4) Top 10 bidhaa (bar chart), (5) Slicers za Mwaka, Mkoa, na Kategoria. Dashboard iwe nzuri na inayoeleweka kwa dakika moja. Wasilisha .pbix file + screenshot. 90 marks, wiki 2.', 90, 14);
    }

    // =========================================================================
    // COURSE 5 — Microsoft Office 365 Business Productivity
    // =========================================================================
    private function officeCourse(int $tid): void
    {
        $c = Course::updateOrCreate(
            ['title' => 'Microsoft Office 365: Uzalendo wa Biashara kwa Mfanyakazi wa Kisasa'],
            [
                'description'    => 'Jifunza kutumia Microsoft Office 365 kwa ufanisi wa biashara — Word kwa hati za kitaalamu, Excel kwa data na ripoti, PowerPoint kwa mawasilisho yanayovutia, Outlook kwa barua pepe na kalenda, na Teams kwa ushirikiano wa timu. Course kamili kwa mfanyakazi wa ofisi wa Tanzania.',
                'category'       => 'microsoft_office',
                'level'          => 'beginner',
                'duration_hours' => 12,
                'price_tzs'      => 0,
                'instructor_id'  => $tid,
                'created_by'     => $tid,
                'status'         => 'pending_approval',
            ]
        );

        $m1 = $this->mod($c, 1, 'Microsoft Word kwa Hati za Kitaalamu', 'Unda hati za kitaalamu — barua, ripoti, CV, na mikataba — kwa kutumia Word kwa ufanisi.');

        $l = $this->lesson($m1, 1, 'Word: Misingi ya Hati za Biashara', 'Formatting ya kitaalamu, styles, headers/footers, table of contents — jinsi ya kuunda hati inayoonekana kitaalamu.', 900,
            [['video_youtube', 'Microsoft Word Tutorial for Beginners', 'https://www.youtube.com/watch?v=S-nHHkKGrNs']],
            '<h2>Microsoft Word — Hati za Kitaalamu</h2><p>Hati ya kitaalamu sio tu kuandika maneno. Muundo wake, mpangilio wake, na uwasilishaji wake vinazungumza kabla hujasoma neno moja. Jifunza kuunda hati zinazovutia na zinazoeleweka.</p><h3>Styles — Nguvu ya Kweli ya Word</h3><p>Styles ni mipangilio ya mapema ya formatting (heading, body text, nk). Badala ya kubadilisha kila kichwa kwa mkono, tumia Heading 1, Heading 2, nk.</p><ul><li>Inafanya Table of Contents kiotomatiki</li><li>Inafanya mabadiliko ya formatting kwa sekunde moja (badilisha style moja — mabadiliko yote yanaathiriwa)</li><li>Inafanya hati ionekane sawa katika hati nzima</li></ul><h3>Header na Footer</h3><p>Insert → Header/Footer. Ongeza: jina la kampuni, nambari ya ukurasa, tarehe moja kwa moja (<code>Alt+Shift+D</code>), logo ya kampuni.</p><h3>Table of Contents (Jedwali la Yaliyomo)</h3><p>Tumia Heading styles kwanza → References → Table of Contents → Chagua muundo → Word inaunda ToC yenyewe!</p><h3>Track Changes (Mabadiliko yanayoonekana)</h3><p>Review → Track Changes → Mabadiliko yote yanaonekana kwa rangi tofauti. Muhimu kwa kazi ya pamoja kwenye hati moja.</p><blockquote><strong>Hati ya Kitaalamu Tanzania:</strong> Barua rasmi za biashara zinatumia: heshima sahihi (Ndugu, Mheshimiwa), salamu za kawaida, lugha rasmi, na saini halisi au kidijitali. Word inaweza kuhifadhi template yako maalum.</blockquote>');
        $this->assignment($l, 'Barua Rasmi ya Biashara na Ripoti', 'Kazi mbili: (1) Andika barua rasmi ya biashara kwa Kiingereza YOYOTE halisi — ombi la ushirikiano, malalamiko, au pendekezo. Lazima iwe na: letterhead (jina la kampuni, anwani), tarehe, anwani ya mpokeaji, mada, mwili wa barua, na saini. (2) Unda ripoti fupi (kurasa 3) kuhusu mada ya biashara ya Tanzania yoyote — tumia Headings, Table of Contents, header/footer, na picha moja. Wasilisha .docx. 40 marks, siku 5.', 40, 5);

        $l = $this->lesson($m1, 2, 'PowerPoint: Mawasilisho Yanayovutia', 'Design ya slides nzuri, animation, na jinsi ya kuwasilisha kwa ujasiri. Epuka "death by PowerPoint."', 1080,
            [['video_youtube', 'PowerPoint Tutorial for Beginners', 'https://www.youtube.com/watch?v=OsLb-fAa_W4']],
            '<h2>PowerPoint — Mawasilisho Yanayovutia</h2><p>"Death by PowerPoint" ni tatizo la kweli — mawasilisho mengi yanachoshea na hayaeleweki. Jifunza kanuni za design zinazofanya mawasilisho yako kuvutia na kukumbukwa.</p><h3>Kanuni 10 za PowerPoint Nzuri</h3><ol><li><strong>Kanuni ya 6×6:</strong> Si zaidi ya mistari 6 kwa slide, si zaidi ya maneno 6 kwa mstari</li><li><strong>Picha badala ya maneno:</strong> Picha moja inasema zaidi ya maneno 100</li><li><strong>Font moja au mbili tu:</strong> Fonts nyingi zinaonekana za amatur</li><li><strong>Rangi 3 au chini:</strong> Tumia color scheme inayosana (Slide Design → Themes)</li><li><strong>Contrast kubwa:</strong> Maandishi meusi kwenye background nyeupe — au nyeupe kwenye giza</li><li><strong>Usizibe slides:</strong> Nafasi tupu ni rafiki yako</li><li><strong>Animations za wastani:</strong> Simple appear/fade — siyo mzunguko na sauti</li><li><strong>Slide ya mwanzo:</strong> Jina, mada, tarehe, mwasilishaji</li><li><strong>Slide ya mwisho:</strong> "Asante" + mawasiliano yako</li><li><strong>Fanya rehearsal:</strong> Jua muda wako kabla ya kuwasilisha</li></ol><h3>Zana Muhimu</h3><ul><li><strong>Slide Master:</strong> Badilisha mwonekano wa slides ZOTE kwa wakati mmoja</li><li><strong>SmartArt:</strong> Diagrams, flowcharts, orodha za hatua — bila kuchora kwa mkono</li><li><strong>Morph transition:</strong> Inaunda animation nzuri ya slide moja hadi nyingine kiotomatiki</li></ul><blockquote><strong>Ushauri wa Steve Jobs:</strong> Mawasilisho yake ya Apple yalikuwa na maneno machache sana, picha kubwa, na ujumbe mmoja kwa kila slide. Jifunze kutoka bora.</blockquote>');
        $this->assignment($l, 'Uwasilishaji wa Biashara', 'Unda PowerPoint ya slides 12-15 kuhusu mada yoyote ya biashara ya Tanzania (fursa za uwekezaji, tatizo la biashara na suluhu, ufafanuzi wa huduma yako). Lazima iwe na: slide ya mwanzo, ToC, maudhui na picha/diagrams (SmartArt), charts, na slide ya mwisho. Fuata kanuni za design. Wasilisha .pptx. 50 marks, siku 7.', 50, 7);

        $m2 = $this->mod($c, 2, 'Teams, Outlook na Ushirikiano wa Timu', 'Jinsi ya kufanya kazi kwa ufanisi na timu yako kwa kutumia Microsoft Teams na Outlook.');

        $l = $this->lesson($m2, 1, 'Microsoft Outlook: Barua Pepe na Kalenda ya Kitaalamu', 'Simamia barua pepe, kalenda, miadi, na kazi kwa Outlook. Jinsi ya kutumia email kwa ufanisi na si kukaa inbox yako yote siku.', 900,
            [['video_youtube', 'Outlook Tutorial for Beginners', 'https://www.youtube.com/watch?v=Suc38LnSPns']],
            '<h2>Microsoft Outlook — Uzalendo wa Barua Pepe</h2><p>Wafanyakazi wengi wanapokelea barua pepe 100+ kwa siku. Bila mfumo mzuri, inbox inakuwa chanzo cha msongo wa mawazo. Jifunza kutumia Outlook kwa ufanisi — kusimamia inbox, kalenda, na kazi kwa njia inayokusaidia badala ya kukuzuia.</p><h3>Mfumo wa Inbox Zero</h3><ol><li><strong>Process mara moja:</strong> Kila barua pepe — fanya moja ya hivi: Futa, Archive, Jibu (dakika 2 au chini), au Panga (task/folder)</li><li><strong>Tumia Folders:</strong> Projects, Clients, Follow-up, Reference — si inbox kama folders</li><li><strong>Flags na Tasks:</strong> Flag barua pepe zinazohitaji hatua — zinakuwa tasks kwenye Task list</li><li><strong>Rules/Filters:</strong> Barua pepe za newsletter → folder moja kwa moja bila kuzisumbua</li></ol><h3>Kalenda ya Kitaalamu</h3><ul><li><strong>Block deep work time:</strong> Weka "Kazi ya Kina" kwenye kalenda kama miadi halisi — watu wengine wataona unavyoshughulika</li><li><strong>Meeting Requests:</strong> Tuma miadi na watu wengine — kalenda zao zitaonyeshwa unapoandika</li><li><strong>Recurring Events:</strong> Mikutano ya kila wiki, ripoti za mwezi — weka mara moja</li><li><strong>Categories na Colors:</strong> Rangi tofauti kwa aina tofauti za miadi</li></ul><blockquote><strong>Tip ya Uzalendo:</strong> Zima notifications za barua pepe kwenye simu yako kwa saa 2-3 unapofanya kazi ngumu. Tafiti zinaonyesha mtu anahitaji dakika 23 kurudi concentration yake baada ya interruption moja.</blockquote>');
        $this->assignment($l, 'Outlook Workflow Setup', 'Unda mfumo wako wa Outlook: (1) Unda folders 5 za msingi kwa kazi yako ya kawaida. (2) Unda rule moja inayopeleka barua pepe fulani kwenye folder moja kwa moja. (3) Weka miadi 3 kwenye kalenda kwa wiki ijayo (moja recurring). (4) Andika barua pepe ya kitaalamu na uyapeleke kwa mtu fulani (au draftia na screenshot). (5) Andika maelezo ya jinsi utakavyosimamia inbox yako — mfumo wako. Wasilisha screenshots + PDF ya maelezo. 40 marks, siku 5.', 40, 5);

        $l = $this->lesson($m2, 2, 'Microsoft Teams: Ushirikiano wa Timu ya Kisasa', 'Teams ni makao makuu ya timu ya kisasa — mazungumzo, mikutano, hati, na kazi — yote mahali pamoja. Jifunza jinsi ya kutumia Teams kwa ufanisi wa kazi.', 1080,
            [['video_youtube', 'Microsoft Teams Tutorial', 'https://www.youtube.com/watch?v=jugBQqE_2sM']],
            '<h2>Microsoft Teams — Makao Makuu ya Timu ya Kisasa</h2><p>Teams imebadilisha jinsi watu wanavyofanya kazi pamoja. Badala ya barua pepe ndefu nyuma na mbele, timu zinazungumza, zinashirikiana kwenye hati, na zinafanya mikutano — zote ndani ya Teams.</p><h3>Muundo wa Teams</h3><ul><li><strong>Teams:</strong> Timu yako (mfano: Finance Department, Project X)</li><li><strong>Channels:</strong> Mada ndogo ndani ya timu (General, Bajeti 2025, Ripoti za Kila Wiki)</li><li><strong>Posts:</strong> Mazungumzo ya timu — wote wanaona</li><li><strong>Files:</strong> Hati zinashirikishwa na kuhifadhiwa moja kwa moja kwenye SharePoint</li><li><strong>Chat:</strong> Mazungumzo ya faragha au kikundi kidogo</li></ul><h3>Mikutano ya Teams</h3><ul><li>Mikutano ya video na audio — watu wanaohudhuriwa au wanaofanya kazi mbali</li><li><strong>Background blur/virtual:</strong> Hali nzuri hata nyumbani</li><li><strong>Record:</strong> Rekodi mikutano — wengine wataweza kuona baadaye</li><li><strong>Whiteboard:</strong> Bodi ya kuchora pamoja kwa wakati halisi</li><li><strong>Breakout Rooms:</strong> Gawanya timu kubwa katika vikundi vidogo</li></ul><h3>Ushirikiano wa Hati</h3><p>Fungua Word, Excel, au PowerPoint moja kwa moja ndani ya Teams — timu nyingi zinaweza kuhariri hati moja kwa wakati mmoja (real-time collaboration).</p><blockquote><strong>Desturi Nzuri za Teams:</strong> (1) Jibu ndani ya thread — siyo nje, (2) @mention mtu ukitaka jibu lake hasa, (3) Tumia reactions (👍❤️) badala ya "Asante" tu — inapunguza kelele, (4) Weka status yako (Available/Busy/Away) ili timu ijue unapohudhuriwa.</blockquote>');
        $this->assignment($l, 'Teams Collaboration Project', 'Kwa pamoja na washirika 2-3 wa course (au peke yako kama practice), fanya yafuatayo katika Teams: (1) Unda Team mpya na channels 3 zinazofaa (au tumia trial). (2) Weka mkutano wa Teams wa dakika 15 — agenda ikiwa na mada moja ya biashara. (3) Shiriki hati ya Word au Excel ndani ya Teams na uifanye mabadiliko. (4) Toa ripoti ya jinsi Teams inaweza kuchangia uzalendo wa timu yako ya kazi — mapendekezo 5 na mifano. Wasilisha PDF + screenshots. 50 marks, siku 7.', 50, 7);
    }

    // =========================================================================
    // Helpers — field names match real migrations exactly
    // =========================================================================
    private function mod(Course $course, int $pos, string $title, string $desc): CourseModule
    {
        return CourseModule::updateOrCreate(
            ['course_id' => $course->id, 'position' => $pos],
            ['title' => $title, 'description' => $desc]
        );
    }

    private function lesson(
        CourseModule $module,
        int $pos,
        string $title,
        string $desc,
        int $durationSecs,
        array $materials,
        string $content = ''
    ): Lesson {
        $lesson = Lesson::updateOrCreate(
            ['course_module_id' => $module->id, 'position' => $pos],
            [
                'title'            => $title,
                'description'      => $desc,
                'duration_seconds' => $durationSecs,
                'content'          => $content ?: null,
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

    private function assignment(
        ?Lesson $lesson,
        string $title,
        string $instructions,
        int $maxPts,
        int $dueDays
    ): void {
        if (!$lesson) return;
        Assignment::updateOrCreate(
            ['lesson_id' => $lesson->id, 'title' => $title],
            [
                'instructions'       => $instructions,
                'max_points'         => $maxPts,
                'due_date'           => now()->addDays($dueDays),
                'allowed_file_types' => ['pdf', 'docx', 'xlsx', 'zip'],
            ]
        );
    }
}
