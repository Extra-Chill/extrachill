<div class="home-newsletter-about-row">
  <div class="home-about-box">
    <h2 class="home-about-header">About Extra Chill</h2>
    <div class="home-about-bio">
      Founded in 2011 in Charleston, SC, and now local to Austin, TX, Extra Chill is a laid-back corner of the music industry. We value storytelling and believe in the power of community. Our platform is a place for the underground to thrive, connect, and grow. 
    </div>
    <div class="home-about-links">
      <a href="/about" class="home-about-link">Learn More</a>
      <a href="https://instagram.com/extrachill" class="home-about-link" target="_blank" rel="noopener noreferrer">Instagram</a>
    </div>
  </div>
  <div class="home-newsletter-signup">
    <h2 class="home-newsletter-header">A Note from the Editor</h2>
    <div class="home-newsletter-subhead">Stories, reflections, and music industry insights from the underground.</div>
    <form id="homepageNewsletterForm" class="newsletter-form">
      <input type="email" id="newsletter-email" name="email" required placeholder="Your email for the inside scoop...">
      <input type="hidden" name="action" value="subscribe_to_sendy_home">
      <?php wp_nonce_field('subscribe_to_sendy_home_nonce', 'subscribe_to_sendy_home_nonce_field'); ?>
      <button type="submit">Get the Letter</button>
    </form>
    <p class="newsletter-feedback" style="display:none;"></p>
  </div>
</div> 