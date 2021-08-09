<?php
/**
 * This template contains the reactions in the footer for an article on /friends/.
 *
 * @version 1.0
 * @package Friends
 */

foreach ( Friends_Reactions::get_post_reactions() as $slug => $reaction ) {
	$classes = array();
	if ( $reaction->user_reacted ) {
		$classes[] = 'pressed';
	}
	echo '<button class="btn ml-1 friends-action friends-reaction ' . esc_attr( implode( ' ', $classes ) ) . '" data-id="' . esc_attr( get_the_ID() ) . '" data-emoji="' . esc_attr( $slug ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'friends-reaction' ) ) . '" title="' . esc_attr( $reaction->usernames ) . '"><span>';
	echo esc_html( $reaction->emoji );
	echo '</span> ' . esc_html( $reaction->count );
	echo '</button>' . PHP_EOL;
}

if ( ( in_array( get_post_type(), Friends::get_frontend_post_types(), true ) || count( $reactions ) || get_the_author_meta( 'ID' ) !== get_current_user_id() ) && ( current_user_can( Friends::REQUIRED_ROLE ) || current_user_can( 'friend' ) || current_user_can( 'acquaintance' ) ) ) :
	?>
	<button class="btn ml-1 friends-action new-reaction" data-id="<?php echo esc_attr( get_the_ID() ); ?>">
		<span>&#xf132;</span> <?php echo esc_html( _x( 'Reaction', '+ Reaction', 'friends' ) ); ?>
	</button>
	<?php
endif;
