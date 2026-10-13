(function() {
  const SALES_EMAIL = 'support@cuboidsoft.in';

  function buildSalesMailto(planName, context) {
    const plan = planName || 'CuboidPilot Pro';
    const company = context && context.company ? context.company : '';
    const subject = `${plan} consultation request`;
    const body = [
      'Hello CuboidPilot Sales Team,',
      '',
      `I am interested in the ${plan} plan and would like to discuss the best setup for my business.`,
      '',
      'Business details:',
      `Company name: ${company}`,
      'Website:',
      'Team size:',
      'Monthly website conversations/leads:',
      'Preferred channels: Website chat / WhatsApp / CRM / Calendar',
      '',
      'What we want to achieve:',
      '- Qualify website visitors automatically',
      '- Capture and route high-intent leads to the right team member',
      '- Use Cai with verified business knowledge and a clean human handoff',
      '',
      'Please share plan recommendations, onboarding steps, pricing, and available demo slots.',
      '',
      'Regards,',
      ''
    ].join('\n');

    return `mailto:${SALES_EMAIL}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;
  }

  window.CuboidSalesMail = {
    build: buildSalesMailto,
    open: function(planName, context) {
      window.location.href = buildSalesMailto(planName, context || {});
    }
  };
})();
