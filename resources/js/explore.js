export default function explore(config) {
    const initialFilters = config.initialFilters || {};
    const searchMode = !!config.searchMode;

    return {
        competitions: config.competitions || [],
        pools: config.pools || [],
        freeAgentCount: config.freeAgentCount || 0,
        freeAgentsLabel: config.labels.freeAgents,
        leagueKindLabel: config.labels.leagueKind,
        poolKindLabel: config.labels.poolKind,
        freeAgentsKindLabel: config.labels.freeAgentsKind,
        searchKindLabel: config.labels.searchKind,
        searchScopeLabel: config.labels.searchScope,
        assetUrl: config.assetUrl || '',
        gameId: config.gameId,
        viewMode: searchMode ? 'search' : 'competition',
        scopePickerOpen: false,
        selectedCompetition: null,
        activePoolId: null,
        activePoolHint: '',
        teams: [],
        selectedTeam: null,
        squadHtml: '',
        loadingTeams: false,
        loadingSquad: false,
        loadingFreeAgents: false,
        loadingPool: false,
        poolGroups: [],
        searchQuery: initialFilters.name || '',
        searching: false,
        mobileView: 'teams',
        selectedPositionFilter: 'all',
        positionFilters: [
            { key: 'all', label: config.labels.positionAll },
            { key: 'gk', label: config.labels.positionGk },
            { key: 'def', label: config.labels.positionDef },
            { key: 'mid', label: config.labels.positionMid },
            { key: 'fwd', label: config.labels.positionFwd },
        ],
        filtersOpen: searchMode,
        filters: {
            position: initialFilters.position || '',
            nationality: initialFilters.nationality || '',
            competition_id: initialFilters.competition_id || '',
            max_contract_year: initialFilters.max_contract_year || null,
        },

        // Dual-range bounds (mirrors scout-search-modal pattern)
        AGE_MIN_BOUND: 16,
        AGE_MAX_BOUND: 40,
        OVERALL_MIN_BOUND: 50,
        OVERALL_MAX_BOUND: 99,
        valueSteps: [0, 500000, 1000000, 2000000, 5000000, 10000000, 20000000, 50000000, 100000000, 200000000],

        ageMin: null,
        ageMax: null,
        overallMin: null,
        overallMax: null,
        valueStepMin: null,
        valueStepMax: null,

        enforceAgeMin() { if (this.ageMin > this.ageMax) this.ageMax = this.ageMin; },
        enforceAgeMax() { if (this.ageMax < this.ageMin) this.ageMin = this.ageMax; },
        enforceOverallMin() { if (this.overallMin > this.overallMax) this.overallMax = this.overallMin; },
        enforceOverallMax() { if (this.overallMax < this.overallMin) this.overallMin = this.overallMax; },
        enforceValueMin() { if (this.valueStepMin > this.valueStepMax) this.valueStepMax = this.valueStepMin; },
        enforceValueMax() { if (this.valueStepMax < this.valueStepMin) this.valueStepMin = this.valueStepMax; },

        ageTrackLeft() { return ((this.ageMin - this.AGE_MIN_BOUND) / (this.AGE_MAX_BOUND - this.AGE_MIN_BOUND)) * 100 + '%'; },
        ageTrackWidth() { return ((this.ageMax - this.ageMin) / (this.AGE_MAX_BOUND - this.AGE_MIN_BOUND)) * 100 + '%'; },
        overallTrackLeft() { return ((this.overallMin - this.OVERALL_MIN_BOUND) / (this.OVERALL_MAX_BOUND - this.OVERALL_MIN_BOUND)) * 100 + '%'; },
        overallTrackWidth() { return ((this.overallMax - this.overallMin) / (this.OVERALL_MAX_BOUND - this.OVERALL_MIN_BOUND)) * 100 + '%'; },
        valueTrackLeft() { return (this.valueStepMin / (this.valueSteps.length - 1)) * 100 + '%'; },
        valueTrackWidth() { return ((this.valueStepMax - this.valueStepMin) / (this.valueSteps.length - 1)) * 100 + '%'; },

        valueMin() { return this.valueSteps[this.valueStepMin]; },
        valueMax() { return this.valueSteps[this.valueStepMax]; },
        formatValue(val) {
            if (val === 0) return '€0';
            if (val >= 1000000) return '€' + (val / 1000000) + 'M';
            if (val >= 1000) return '€' + (val / 1000) + 'K';
            return '€' + val;
        },

        get ageActive() { return this.ageMin > this.AGE_MIN_BOUND || this.ageMax < this.AGE_MAX_BOUND; },
        get overallActive() { return this.overallMin > this.OVERALL_MIN_BOUND || this.overallMax < this.OVERALL_MAX_BOUND; },
        get valueActive() { return this.valueStepMin > 0 || this.valueStepMax < this.valueSteps.length - 1; },

        get activeFilterCount() {
            let n = 0;
            if (this.filters.position) n++;
            if (this.filters.nationality) n++;
            if (this.filters.competition_id) n++;
            if (this.filters.max_contract_year) n++;
            if (this.ageActive) n++;
            if (this.overallActive) n++;
            if (this.valueActive) n++;
            return n;
        },
        get hasAnyCriteria() {
            return this.searchQuery.trim().length >= 2 || this.activeFilterCount > 0;
        },

        init() {
            this.initRangesFromFilters();

            // The <select name="competition_id"> renders its options via
            // <template x-for>. Alpine evaluates x-model before x-for has
            // populated those <option> nodes, so a server-prefilled
            // competition_id silently falls back to the empty default.
            // Re-assign on the next tick once the options exist so the
            // select actually reflects the search criteria the user
            // submitted.
            if (initialFilters.competition_id) {
                this.$nextTick(() => {
                    const sel = this.$el.querySelector('select[name="competition_id"]');
                    if (sel) sel.value = initialFilters.competition_id;
                });
            }

            if (!searchMode && this.competitions.length > 0) {
                this.selectCompetition(this.competitions[0]);
            }
        },

        initRangesFromFilters() {
            const f = initialFilters;
            this.ageMin = f.min_age ? Number(f.min_age) : this.AGE_MIN_BOUND;
            this.ageMax = f.max_age ? Number(f.max_age) : this.AGE_MAX_BOUND;
            this.overallMin = f.min_overall ? Number(f.min_overall) : this.OVERALL_MIN_BOUND;
            this.overallMax = f.max_overall ? Number(f.max_overall) : this.OVERALL_MAX_BOUND;
            this.valueStepMin = f.min_value ? this.stepForValue(Number(f.min_value), 0) : 0;
            this.valueStepMax = f.max_value ? this.stepForValue(Number(f.max_value), this.valueSteps.length - 1) : this.valueSteps.length - 1;
        },

        stepForValue(value, fallback) {
            const idx = this.valueSteps.indexOf(value);
            return idx >= 0 ? idx : fallback;
        },

        async selectCompetition(comp) {
            this.viewMode = 'competition';
            this.selectedCompetition = comp;
            this.selectedTeam = null;
            this.squadHtml = '';
            if (this.$refs.squadPanel) this.$refs.squadPanel.innerHTML = '';
            if (this.$refs.poolSquadPanel) this.$refs.poolSquadPanel.innerHTML = '';
            this.loadingTeams = true;

            try {
                const response = await fetch(`/game/${this.gameId}/explore/teams/${comp.id}`);
                this.teams = await response.json();
            } catch (e) {
                this.teams = [];
            } finally {
                this.loadingTeams = false;
            }
        },

        async selectTeam(team) {
            this.selectedTeam = team;
            this.loadingSquad = true;
            this.mobileView = 'squad';

            const panel = this.viewMode === 'pool' ? this.$refs.poolSquadPanel : this.$refs.squadPanel;

            try {
                const response = await fetch(`/game/${this.gameId}/explore/squad/${team.id}`);
                const html = await response.text();
                this.squadHtml = html;
                if (panel) {
                    panel.innerHTML = html;
                    this.$nextTick(() => window.Alpine.initTree(panel));
                }
            } catch (e) {
                this.squadHtml = '';
                if (panel) panel.innerHTML = '';
            } finally {
                this.loadingSquad = false;
            }
        },

        async selectPool(pool) {
            this.viewMode = 'pool';
            this.selectedCompetition = null;
            this.selectedTeam = null;
            this.squadHtml = '';
            if (this.$refs.poolSquadPanel) this.$refs.poolSquadPanel.innerHTML = '';
            this.mobileView = 'teams';

            const switching = this.activePoolId !== pool.id;
            this.activePoolId = pool.id;
            this.activePoolHint = pool.hint || '';

            if (!switching && this.poolGroups.length > 0) return;

            this.poolGroups = [];
            this.loadingPool = true;
            try {
                const response = await fetch(`/game/${this.gameId}/explore/pool-teams/${pool.id}`);
                this.poolGroups = await response.json();
            } catch (e) {
                this.poolGroups = [];
            } finally {
                this.loadingPool = false;
            }
        },

        get activeScope() {
            if (this.viewMode === 'competition' && this.selectedCompetition) {
                return {
                    kindLabel: this.leagueKindLabel,
                    label: this.selectedCompetition.name,
                    flag: this.selectedCompetition.flag || null,
                    emoji: null,
                    icon: null,
                    count: this.selectedCompetition.teamCount,
                };
            }
            if (this.viewMode === 'pool' && this.activePoolId) {
                const pool = this.pools.find(p => p.id === this.activePoolId);
                if (pool) {
                    return {
                        kindLabel: this.poolKindLabel,
                        label: pool.label,
                        flag: pool.flag,
                        emoji: pool.emoji || null,
                        icon: null,
                        count: pool.count,
                    };
                }
            }
            if (this.viewMode === 'freeAgents') {
                return {
                    kindLabel: this.freeAgentsKindLabel,
                    label: this.freeAgentsLabel,
                    flag: null,
                    emoji: null,
                    icon: null,
                    count: this.freeAgentCount,
                };
            }
            if (this.viewMode === 'search') {
                return {
                    kindLabel: this.searchKindLabel,
                    label: this.searchScopeLabel,
                    flag: null,
                    emoji: null,
                    icon: 'search',
                    count: null,
                };
            }
            // Fallback for the brief moment before the first scope is selected.
            const firstComp = this.competitions[0];
            return firstComp
                ? { kindLabel: this.leagueKindLabel, label: firstComp.name, flag: firstComp.flag || null, emoji: null, icon: null, count: firstComp.teamCount }
                : { kindLabel: '', label: '—', flag: null, emoji: null, icon: null, count: 0 };
        },

        selectFreeAgents() {
            this.viewMode = 'freeAgents';
            this.selectedCompetition = null;
            this.selectedPositionFilter = 'all';
            this.mobileView = 'teams';
            this.loadFreeAgents('all');
        },

        selectPositionFilter(position) {
            this.selectedPositionFilter = position;
            this.mobileView = 'squad';
            this.loadFreeAgents(position);
        },

        async loadFreeAgents(position) {
            this.loadingFreeAgents = true;

            try {
                const response = await fetch(`/game/${this.gameId}/explore/free-agents?position=${position}`);
                const html = await response.text();
                this.$refs.freeAgentPanel.innerHTML = html;
                this.$nextTick(() => window.Alpine.initTree(this.$refs.freeAgentPanel));
            } catch (e) {
                if (this.$refs.freeAgentPanel) this.$refs.freeAgentPanel.innerHTML = '';
            } finally {
                this.loadingFreeAgents = false;
            }
        },
    };
}
