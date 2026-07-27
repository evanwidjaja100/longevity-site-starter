# Production-Readiness Repository Analysis Prompt

I want you to perform a comprehensive, repository-wide technical assessment of this project and determine what is required to make it production-ready.

Begin by thoroughly exploring and understanding the entire repository before making recommendations. Do not rush to conclusions based only on the README, entry point, or a small sample of files. Examine the architecture, source code, configuration, dependencies, database structure, APIs, tests, deployment setup, documentation, and development workflows.

## Primary Objectives

1. Understand what the application does, how it is structured, and how its major components interact.
2. Identify technical risks, design weaknesses, security vulnerabilities, reliability concerns, and maintainability issues.
3. Evaluate whether the project is ready for a real production environment.
4. Provide specific, actionable, and prioritized recommendations for improving it.
5. Create a practical roadmap for moving the project from its current state to production readiness.

## Analysis Scope

Review all relevant areas of the repository, including:

### 1. Project Purpose and Architecture

- Determine the application’s purpose, primary users, and core workflows.
- Map the main modules, services, components, and dependencies.
- Explain the overall architecture and request/data flow.
- Identify architectural patterns currently being used.
- Evaluate separation of concerns, modularity, coupling, cohesion, and scalability.
- Identify duplicated logic, circular dependencies, overly complex modules, and unclear boundaries.
- Note any assumptions you must make because of missing documentation.

### 2. Code Quality and Maintainability

Evaluate:

- Code organization and readability
- Naming conventions
- Error-handling patterns
- Repeated or dead code
- Large or overly complex functions and classes
- Type safety and validation
- Configuration management
- Hardcoded values
- Comments and documentation quality
- Consistency between different modules
- Adherence to the language and framework’s recommended practices
- Technical debt that could create future maintenance problems

Reference specific files, modules, functions, or code sections whenever possible.

### 3. Security

Perform a security-focused review covering:

- Authentication and authorization
- Role and permission enforcement
- Session and token management
- Password handling
- Secret and credential management
- Input validation and sanitization
- SQL injection
- Cross-site scripting
- Cross-site request forgery
- Server-side request forgery
- Command injection
- Path traversal
- Insecure file uploads
- Sensitive-data exposure
- Insecure direct object references
- CORS and security headers
- Rate limiting
- Logging of sensitive information
- Dependency vulnerabilities
- Unsafe default configurations
- Environment variable handling
- API endpoint protection

Clearly distinguish confirmed vulnerabilities from potential risks that require runtime verification.

### 4. Data and Database Layer

Assess:

- Database schema and relationships
- Migration strategy
- Indexes and query efficiency
- Transaction handling
- Data validation and integrity constraints
- Concurrency and race-condition risks
- Connection management
- Backup and recovery considerations
- Seed and test data
- Handling of personally identifiable or sensitive data
- Database configuration for development, testing, staging, and production

Identify queries or data-access patterns that may cause performance or consistency problems.

### 5. API and Integration Design

Review:

- API structure and endpoint consistency
- Request and response validation
- Error-response formats
- HTTP status-code usage
- Authentication and authorization enforcement
- Pagination, filtering, and sorting
- Idempotency
- Versioning
- Timeouts and retries
- External-service integrations
- Webhooks
- Failure handling
- Contract documentation
- Backward compatibility

### 6. Frontend and User Experience

Where applicable, evaluate:

- Component organization
- State management
- Routing
- Form validation
- Error and loading states
- Accessibility
- Responsive behavior
- Browser compatibility
- Client-side security
- Performance
- Asset optimization
- API interaction patterns
- User-facing error messages
- Separation between presentation and business logic

### 7. Testing and Quality Assurance

Assess:

- Unit tests
- Integration tests
- End-to-end tests
- API tests
- Security tests
- Test isolation
- Mocking strategy
- Fixtures and test data
- Coverage of critical business paths
- Test reliability and maintainability
- Missing edge-case and failure-path tests

Do not judge testing quality based only on coverage percentages. Evaluate whether important production scenarios are meaningfully tested.

Recommend a testing strategy and identify the highest-priority tests that should be added first.

### 8. Performance and Scalability

Identify possible issues involving:

- Slow database queries
- N+1 queries
- Unnecessary network requests
- Blocking operations
- Memory usage
- CPU-intensive work
- Large payloads
- Inefficient loops or transformations
- Missing caching
- Connection-pool configuration
- Background jobs
- File processing
- Static asset delivery
- Horizontal scaling
- Shared state
- Load balancing
- Rate limiting
- Resource exhaustion

Explain which findings are visible from static analysis and which require profiling or load testing.

### 9. Reliability and Operational Readiness

Evaluate:

- Exception handling
- Graceful degradation
- Retry behavior
- Timeouts
- Circuit breakers
- Health checks
- Readiness and liveness checks
- Structured logging
- Metrics
- Tracing
- Alerting
- Audit trails
- Background-job reliability
- Graceful shutdown
- Disaster recovery
- Backup strategy
- Rollback capability
- Data migration safety

### 10. Configuration and Environment Management

Review:

- Environment variable usage
- Development, testing, staging, and production separation
- Secret management
- Configuration defaults
- Debug settings
- Production-specific configuration
- Feature flags
- Build-time versus runtime configuration
- Configuration validation during startup

Identify any configuration that could cause insecure or unstable production behavior.

### 11. Dependencies and Supply-Chain Risk

Review:

- Direct and transitive dependencies
- Outdated or deprecated packages
- Unmaintained libraries
- Unnecessary dependencies
- Version pinning
- Lockfiles
- License concerns
- Known security risks
- Build scripts
- Package-manager configuration

Do not upgrade dependencies automatically. Explain the risks, compatibility implications, and recommended upgrade order.

### 12. CI/CD and Deployment

Assess:

- Build process
- Continuous integration
- Automated testing
- Linting and formatting
- Static analysis
- Security scanning
- Artifact creation
- Deployment automation
- Environment promotion
- Migration execution
- Rollback process
- Release versioning
- Containerization
- Infrastructure configuration
- Production startup commands

Recommend a safe CI/CD pipeline suitable for this project.

### 13. Documentation and Developer Experience

Evaluate:

- README accuracy
- Installation instructions
- Local development setup
- Environment variable documentation
- Architecture documentation
- API documentation
- Database migration instructions
- Testing instructions
- Deployment documentation
- Troubleshooting guidance
- Contribution standards
- Onboarding experience

Identify documentation that is missing, inaccurate, or likely to cause setup errors.

## Required Working Method

Follow this process:

1. Inventory the repository structure.
2. Identify the technology stack and application entry points.
3. Trace the most important user and data flows.
4. Review configuration and environment files.
5. Review authentication, authorization, and security-sensitive code.
6. Review database and external integrations.
7. Review testing, build, deployment, and operational tooling.
8. Consolidate findings only after developing a repository-wide understanding.

Do not modify any files during the initial assessment. First provide the analysis and recommended plan. Changes can be implemented afterward in controlled phases.

Do not claim that something is secure, scalable, or production-ready unless the repository provides sufficient evidence. Clearly label:

- Confirmed findings
- Probable risks
- Assumptions
- Items requiring runtime testing
- Items requiring business or infrastructure clarification

## Required Output

Organize your final report using the following structure:

### A. Executive Summary

Provide a concise explanation of:

- What the application does
- How it is currently structured
- Its overall maturity
- Whether it is production-ready
- The most significant risks
- The most important next actions

### B. Repository and Architecture Overview

Include:

- Technology stack
- Major directories and their responsibilities
- Main application components
- Important data flows
- External services and infrastructure dependencies
- A text-based architecture diagram where useful

### C. Production-Readiness Scorecard

Rate each area from 1 to 10:

- Architecture
- Code quality
- Security
- Testing
- Performance
- Reliability
- Observability
- Database design
- Deployment
- Documentation
- Maintainability
- Overall production readiness

Explain the reason for every score.

### D. Detailed Findings

For every finding, provide:

- Title
- Severity: Critical, High, Medium, Low, or Informational
- Category
- Evidence
- Relevant file or module
- Why it matters
- Realistic failure or attack scenario
- Recommended solution
- Implementation complexity
- Dependencies or possible side effects

### E. Prioritized Risk Register

Create a table with:

- Priority
- Finding
- Severity
- Likelihood
- Impact
- Recommended action
- Estimated effort
- Whether it blocks production

### F. Production Blockers

List only the issues that must be resolved before deployment to real users.

Separate them into:

- Security blockers
- Data-integrity blockers
- Reliability blockers
- Deployment blockers
- Compliance or privacy blockers

### G. Recommended Implementation Roadmap

Divide the work into phases:

#### Phase 0: Immediate Critical Fixes

Issues that present an immediate security, data-loss, or deployment risk.

#### Phase 1: Production Blockers

Changes required before the first production release.

#### Phase 2: Reliability and Maintainability

Improvements needed to support stable ongoing operation.

#### Phase 3: Performance and Scalability

Optimizations that should be implemented as usage grows.

#### Phase 4: Long-Term Architecture Improvements

Larger refactors and strategic improvements that should not delay essential production fixes unless they are blockers.

For each phase, specify:

- Tasks
- Recommended order
- Expected benefit
- Risk
- Dependencies
- Verification criteria

### H. Testing Strategy

Recommend:

- The first tests that should be implemented
- Critical workflows requiring integration or end-to-end coverage
- Security tests
- Performance tests
- CI quality gates
- Suggested minimum acceptance criteria before release

### I. Deployment and Operations Checklist

Provide a practical checklist for:

- Environment configuration
- Secret management
- Database migrations
- Backups
- Logging
- Monitoring
- Alerting
- Health checks
- Security headers
- HTTPS
- Rate limiting
- CI/CD
- Rollback
- Incident response
- Post-deployment verification

### J. Clarifying Questions

At the end, list questions whose answers would materially affect the assessment, such as:

- Expected number of users and traffic
- Hosting environment
- Regulatory requirements
- Sensitive-data categories
- Availability targets
- Recovery objectives
- Supported regions
- Third-party integrations
- Deployment architecture
- Team size and maintenance capacity

## Quality Requirements

- Be specific rather than generic.
- Ground recommendations in repository evidence.
- Include file paths and code references where available.
- Explain the reasoning and production impact behind each recommendation.
- Prioritize findings by risk, not by convenience.
- Avoid recommending a complete rewrite unless there is strong technical justification.
- Prefer incremental and low-risk improvements where practical.
- Consider security, maintainability, performance, reliability, scalability, observability, and developer experience together.
- Do not hide uncertainty. Clearly explain what cannot be confirmed through static repository analysis.
- Take the time needed to understand the repository before producing the final assessment.
